<?php

namespace App\Services\Olt;

use App\Enums\OnuStatus;
use App\Enums\SyncStatus;
use App\Models\Olt;
use App\Models\Onu;
use App\Models\SyncLog;
use App\Services\Olt\Data\OnuInfo;
use App\Services\Olt\Data\PortInfo;
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
            if (! $olt->shouldSimulate() && ! $driver->probe($olt)) {
                return $this->finish($olt, $log, SyncStatus::Failed, 'OLT unreachable via SNMP.', [], $startedAt);
            }

            $system = $driver->fetchSystem($olt);
            $ports = $driver->fetchPorts($olt);
            $onus = $driver->fetchOnus($olt);

            DB::transaction(function () use ($olt, $ports, $onus, $system) {
                $portMap = $this->upsertPorts($olt, $ports);
                $this->upsertOnus($olt, $onus, $portMap);
                $this->refreshRollups($olt, $system);
            });

            $stats = [
                'ports' => count($ports),
                'onus' => count($onus),
                'online' => collect($onus)->where('status', OnuStatus::Online)->count(),
            ];

            $status = empty($onus) && empty($ports) ? SyncStatus::Partial : SyncStatus::Success;
            $message = $status === SyncStatus::Partial
                ? 'Connected, but no ports/ONUs returned (check vendor OID map).'
                : "Synced {$stats['ports']} ports, {$stats['onus']} ONUs.";

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

            $existing = ['index' => $onu->onu_index, 'status' => $onu->status, 'online_since' => $onu->online_since];
            $onu->fill($this->onuRow($info, $onu->olt_port_id, $existing));
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

        // Load existing state once to preserve "live since" across syncs.
        $existing = $olt->onus()
            ->get(['id', 'onu_index', 'status', 'online_since'])
            ->keyBy('onu_index');

        $rows = [];
        foreach ($onus as $info) {
            $prev = $existing->get($info->onuIndex);
            $portId = $info->portIndex !== null ? ($portMap[$info->portIndex] ?? null) : null;

            $rows[] = array_merge(
                ['olt_id' => $olt->id, 'onu_index' => $info->onuIndex],
                $this->onuRow($info, $portId, $prev ? [
                    'status' => $prev->status,
                    'online_since' => $prev->online_since,
                ] : null),
                ['created_at' => now(), 'updated_at' => now()],
            );
        }

        // Single bulk upsert keyed by (olt_id, onu_index).
        Onu::upsert(
            $rows,
            ['olt_id', 'onu_index'],
            ['olt_port_id', 'serial_number', 'mac_address', 'description', 'status',
                'rx_power', 'tx_power', 'distance', 'online_since', 'last_seen_at',
                'last_synced_at', 'updated_at'],
        );
    }

    /**
     * Build a persistable ONU attribute row, preserving online_since when the
     * ONU was already online (so "live since" is a stable timestamp).
     *
     * @param  array{status?:mixed, online_since?:mixed}|null  $prev
     */
    private function onuRow(OnuInfo $info, ?int $portId, ?array $prev): array
    {
        $now = now();
        $isOnline = $info->status === OnuStatus::Online;

        $onlineSince = null;
        if ($isOnline) {
            $prevOnline = $prev && ($prev['status'] ?? null) === OnuStatus::Online;
            $onlineSince = $prevOnline && ! empty($prev['online_since'])
                ? Carbon::parse($prev['online_since'])
                : ($info->onlineSince ?? $now);
        }

        return [
            'olt_port_id' => $portId,
            'serial_number' => $info->serialNumber,
            'mac_address' => $info->macAddress,
            'description' => $info->description,
            'status' => $info->status->value,
            'rx_power' => $info->rxPower,
            'tx_power' => $info->txPower,
            'distance' => $info->distance,
            'online_since' => $onlineSince,
            'last_seen_at' => $isOnline ? $now : ($prev['online_since'] ?? null),
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

    private function finish(Olt $olt, SyncLog $log, SyncStatus $status, string $message, array $stats, float $startedAt, bool $refreshOlt = true): SyncLog
    {
        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

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
}
