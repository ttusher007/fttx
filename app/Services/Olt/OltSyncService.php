<?php

namespace App\Services\Olt;

use App\Enums\OnuStatus;
use App\Enums\SyncStatus;
use App\Models\Olt;
use App\Models\Onu;
use App\Models\SyncLog;
use App\Services\Olt\Data\OnuInfo;
use App\Services\Olt\Data\PortInfo;
use App\Services\Olt\Drivers\AbstractVendorDriver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Orchestrates a full or targeted sync of an OLT: pulls system info, ports and
 * ONUs through the vendor driver, persists everything atomically, refreshes the
 * cached rollups used by the dashboard, and records a SyncLog audit row.
 */
class OltSyncService
{
    /** MySQL allows 65535 bind placeholders per prepared statement. */
    private const MYSQL_MAX_PLACEHOLDERS = 65535;

    /** SQLite default bind-parameter ceiling (conservative for tests). */
    private const SQLITE_MAX_PLACEHOLDERS = 999;

    /**
     * Columns that CLI enrichment also writes. When SNMP returned none of
     * these for an OLT that has CLI enabled, we leave them untouched so the
     * (slower) CLI values are not wiped every SNMP cycle.
     */
    private const OPTICAL_COLUMNS = ['rx_power', 'tx_power', 'olt_rx_power'];

    private const MAC_COLUMNS = ['mac_address', 'mac_count', 'mac_source'];

    public function __construct(private readonly VendorDriverManager $drivers) {}

    /**
     * Full sync of one OLT. Always returns the SyncLog (even on failure).
     */
    public function sync(Olt $olt, string $trigger = 'schedule', ?int $userId = null): SyncLog
    {
        $log = $olt->syncLogs()->create([
            'type' => 'olt',
            'trigger' => $trigger,
            'status' => SyncStatus::Running,
            'triggered_by' => $userId,
            'started_at' => now(),
        ]);

        $startedAt = microtime(true);
        $driver = $this->drivers->for($olt);

        try {
            $this->progress($log, 'Connecting via SNMP…');

            if (! $olt->shouldSimulate() && ! $driver->probe($olt)) {
                return $this->finish($olt, $log, SyncStatus::Failed, 'OLT unreachable via SNMP.', [], $startedAt);
            }

            $this->progress($log, 'Fetching system information…');
            $system = $driver->fetchSystem($olt);

            $this->progress($log, 'Fetching PON ports…');
            $ports = $driver->fetchPorts($olt);

            $this->progress($log, 'Fetching ONUs (this may take a minute)…');
            $onus = $driver->fetchOnus($olt);

            $this->progress($log, 'Saving ports and ONUs to database…');
            DB::transaction(function () use ($olt, $ports, $onus, $system) {
                $portMap = $this->upsertPorts($olt, $ports);
                $this->upsertOnus($olt, $onus, $portMap);
                $this->refreshRollups($olt, $system);
            });

            $stats = [
                'ports' => count($ports),
                'onus' => count($onus),
                'online' => collect($onus)->where('status', OnuStatus::Online)->count(),
                'with_power' => collect($onus)->filter(fn (OnuInfo $o) => $o->rxPower !== null)->count(),
                'with_mac' => collect($onus)->filter(fn (OnuInfo $o) => $o->macAddress !== null)->count(),
            ];
            if ($driver instanceof AbstractVendorDriver && $driver->notes) {
                $stats['notes'] = array_slice($driver->notes, 0, 20);
            }

            $status = empty($onus) && empty($ports) ? SyncStatus::Partial : SyncStatus::Success;
            $message = $status === SyncStatus::Partial
                ? 'Connected, but no ports/ONUs returned (check vendor OID map).'
                : "Synced {$stats['ports']} ports, {$stats['onus']} ONUs ({$stats['with_power']} with optical power, {$stats['with_mac']} with MAC).";

            return $this->finish($olt, $log, $status, $message, $stats, $startedAt);
        } catch (Throwable $e) {
            Log::error('OLT sync failed', ['olt' => $olt->id, 'error' => $e->getMessage()]);

            return $this->finish($olt, $log, SyncStatus::Failed, $e->getMessage(), [], $startedAt);
        }
    }

    /**
     * Targeted refresh of a single ONU.
     */
    public function syncOnu(Onu $onu, string $trigger = 'manual', ?int $userId = null): SyncLog
    {
        $olt = $onu->olt;
        $log = $olt->syncLogs()->create([
            'onu_id' => $onu->id,
            'type' => 'onu',
            'trigger' => $trigger,
            'status' => SyncStatus::Running,
            'triggered_by' => $userId,
            'started_at' => now(),
        ]);

        $startedAt = microtime(true);

        try {
            $info = $this->drivers->for($olt)->fetchOnu($olt, $onu->onu_index);

            if (! $info) {
                return $this->finish($olt, $log, SyncStatus::Failed, 'ONU not found on OLT.', [], $startedAt, refreshOlt: false);
            }

            $existing = $this->previousState($onu);
            $row = $this->onuRow($info, $onu->olt_port_id, $existing);

            // Same preservation rule as the bulk path: don't wipe CLI-sourced values.
            if ($olt->cli_enabled) {
                if ($info->rxPower === null && $info->txPower === null && $info->oltRxPower === null) {
                    unset($row['rx_power'], $row['tx_power'], $row['olt_rx_power']);
                }
                if ($info->macAddress === null) {
                    unset($row['mac_address'], $row['mac_count'], $row['mac_source']);
                }
            }

            $onu->fill($row);
            $onu->save();

            return $this->finish($olt, $log, SyncStatus::Success, 'ONU refreshed.', ['onu' => 1], $startedAt, refreshOlt: false);
        } catch (Throwable $e) {
            return $this->finish($olt, $log, SyncStatus::Failed, $e->getMessage(), [], $startedAt, refreshOlt: false);
        }
    }

    /**
     * @param  array<int, PortInfo>  $ports
     * @return array<string, int> port_index => olt_port id
     */
    private function upsertPorts(Olt $olt, array $ports): array
    {
        foreach ($ports as $port) {
            $olt->ports()->updateOrCreate(
                ['port_index' => $port->portIndex],
                [
                    'name' => $port->name,
                    'admin_status' => $port->adminStatus,
                    'oper_status' => $port->operStatus,
                ],
            );
        }

        return $olt->ports()->pluck('id', 'port_index')->all();
    }

    /**
     * @param  array<int, OnuInfo>  $onus
     * @param  array<string, int>  $portMap
     */
    private function upsertOnus(Olt $olt, array $onus, array $portMap): void
    {
        if (empty($onus)) {
            return;
        }

        // Load existing state once to preserve "live since" and last-known
        // identifiers (MAC/serial) across syncs.
        $existing = $olt->onus()
            ->get(['id', 'onu_index', 'status', 'online_since', 'last_seen_at', 'mac_address', 'mac_count', 'mac_source', 'onu_mac', 'serial_number', 'model'])
            ->keyBy('onu_index');

        $rows = [];
        foreach ($onus as $info) {
            $prev = $existing->get($info->onuIndex);
            $portId = $info->portIndex !== null ? ($portMap[$info->portIndex] ?? null) : null;

            $rows[] = array_merge(
                ['olt_id' => $olt->id, 'onu_index' => $info->onuIndex],
                $this->onuRow($info, $portId, $prev ? $this->previousState($prev) : null),
                ['created_at' => now(), 'updated_at' => now()],
            );
        }

        $uniqueBy = ['olt_id', 'onu_index'];
        $updateColumns = [
            'olt_port_id', 'serial_number', 'mac_address', 'onu_mac', 'mac_count', 'mac_source',
            'name', 'description', 'model', 'status',
            'rx_power', 'tx_power', 'olt_rx_power', 'distance',
            'online_since', 'last_down_at', 'last_down_cause', 'last_seen_at',
            'last_synced_at', 'updated_at',
        ];

        // If this OLT is CLI-enriched and SNMP gave us no optical / MAC data at
        // all, keep whatever the CLI job wrote instead of nulling it.
        if ($olt->cli_enabled) {
            $anyPower = collect($onus)->contains(fn (OnuInfo $o) => $o->rxPower !== null || $o->txPower !== null || $o->oltRxPower !== null);
            $anyMac = collect($onus)->contains(fn (OnuInfo $o) => $o->macAddress !== null);
            if (! $anyPower) {
                $updateColumns = array_values(array_diff($updateColumns, self::OPTICAL_COLUMNS));
            }
            if (! $anyMac) {
                $updateColumns = array_values(array_diff($updateColumns, self::MAC_COLUMNS));
            }
        }

        foreach (array_chunk($rows, $this->onuUpsertBatchSize(count($rows[0]))) as $chunk) {
            Onu::upsert($chunk, $uniqueBy, $updateColumns);
        }

        // Prune ONUs that are no longer reported by the OLT (deprovisioned, or
        // left over from an earlier driver that indexed them differently). Safe
        // because this only runs when the fetch returned ONUs (guarded above).
        $seen = array_map(static fn (OnuInfo $info) => $info->onuIndex, $onus);
        $olt->onus()->whereNotIn('onu_index', $seen)->delete();
    }

    /** @return array<string, mixed> */
    private function previousState(Onu $onu): array
    {
        return [
            'status' => $onu->status,
            'online_since' => $onu->online_since,
            'last_seen_at' => $onu->last_seen_at,
            'mac_address' => $onu->mac_address,
            'mac_count' => $onu->mac_count,
            'mac_source' => $onu->mac_source,
            'onu_mac' => $onu->onu_mac,
            'serial_number' => $onu->serial_number,
            'model' => $onu->model,
        ];
    }

    /**
     * Build a persistable ONU attribute row, preserving online_since when the
     * ONU was already online (so "live since" is a stable timestamp) and the
     * last-known MAC / serial when the OLT no longer reports one (an offline
     * ONU disappears from the FDB, but customers still need to be looked up).
     *
     * @param  array<string, mixed>|null  $prev
     */
    private function onuRow(OnuInfo $info, ?int $portId, ?array $prev): array
    {
        $now = now();
        $isOnline = $info->status === OnuStatus::Online;

        $onlineSince = null;
        if ($isOnline) {
            if ($info->onlineSince !== null) {
                // OLT reports actual uptime — use it directly (resets on reconnection).
                $onlineSince = $info->onlineSince;
            } else {
                // No OLT uptime: preserve existing online_since so it stays stable.
                $prevOnline = $prev && ($prev['status'] ?? null) === OnuStatus::Online;
                $onlineSince = $prevOnline && ! empty($prev['online_since'])
                    ? Carbon::parse($prev['online_since'])
                    : $now;
            }
        }

        $mac = $info->macAddress ?? ($prev['mac_address'] ?? null);
        $macSource = $info->macAddress ? $info->macSource : ($prev['mac_source'] ?? null);
        $macCount = $info->macAddress ? $info->macCount : ($prev['mac_count'] ?? null);

        return [
            'olt_port_id' => $portId,
            'serial_number' => $info->serialNumber ?? ($prev['serial_number'] ?? null),
            'mac_address' => $mac,
            'onu_mac' => $info->onuMac ?? ($prev['onu_mac'] ?? null),
            'mac_count' => $macCount,
            'mac_source' => $mac ? $macSource : null,
            'name' => $info->name,
            'description' => $info->description,
            'model' => $info->model ?? ($prev['model'] ?? null),
            'status' => $info->status->value,
            'rx_power' => $info->rxPower,
            'tx_power' => $info->txPower,
            'olt_rx_power' => $info->oltRxPower,
            'distance' => $info->distance,
            'online_since' => $onlineSince,
            'last_down_at' => $info->lastDownAt,
            'last_down_cause' => $info->lastDownCause,
            'last_seen_at' => $isOnline ? $now : ($prev['last_seen_at'] ?? null),
            'last_synced_at' => $now,
        ];
    }

    private function refreshRollups(Olt $olt, $system): void
    {
        // Per-port counts.
        $perPort = $olt->onus()
            ->selectRaw('olt_port_id, COUNT(*) as total, SUM(status = ?) as online', [OnuStatus::Online->value])
            ->groupBy('olt_port_id')
            ->get();

        foreach ($perPort as $row) {
            if ($row->olt_port_id) {
                $olt->ports()->whereKey($row->olt_port_id)->update([
                    'onu_count' => $row->total,
                    'onu_online_count' => $row->online,
                ]);
            }
        }

        $total = $olt->onus()->count();
        $online = $olt->onus()->where('status', OnuStatus::Online->value)->count();

        $olt->forceFill([
            'model' => $olt->model ?: $this->guessModel($system),
            'port_count' => $olt->ports()->count(),
            'onu_count' => $total,
            'onu_online_count' => $online,
            'onu_offline_count' => $total - $online,
        ])->save();
    }

    private function guessModel($system): ?string
    {
        $descr = $system->description ?? null;

        return $descr ? mb_substr($descr, 0, 120) : null;
    }

    private function progress(SyncLog $log, string $message): void
    {
        $log->update(['message' => $this->shortenSyncMessage($message)]);
    }

    private function finish(Olt $olt, SyncLog $log, SyncStatus $status, string $message, array $stats, float $startedAt, bool $refreshOlt = true): SyncLog
    {
        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
        $message = $this->shortenSyncMessage($message);

        $log->update([
            'status' => $status,
            'message' => $message,
            'stats' => $stats,
            'duration_ms' => $durationMs,
            'finished_at' => now(),
        ]);

        if ($refreshOlt) {
            $olt->forceFill([
                'last_sync_status' => $status->value,
                'last_sync_message' => $message,
                'last_synced_at' => now(),
                'last_sync_duration_ms' => $durationMs,
            ])->save();
        }

        return $log;
    }

    private function onuUpsertBatchSize(int $columns): int
    {
        $max = DB::connection()->getDriverName() === 'sqlite'
            ? self::SQLITE_MAX_PLACEHOLDERS
            : self::MYSQL_MAX_PLACEHOLDERS;

        return max(1, intdiv($max, max(1, $columns)) - 1);
    }

    /**
     * Laravel SQL exceptions embed the full query (often megabytes on bulk
     * upserts). Keep only the human-readable error for sync_logs / OLT status.
     */
    private function shortenSyncMessage(string $message): string
    {
        $message = preg_replace('/\s*\(Connection:.*$/s', '', $message) ?? $message;
        $message = preg_replace('/\s*\(SQL:.*$/s', '', $message) ?? $message;

        return mb_substr(trim($message), 0, 2000);
    }
}
