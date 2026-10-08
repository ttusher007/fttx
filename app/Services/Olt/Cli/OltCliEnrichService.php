<?php

namespace App\Services\Olt\Cli;

use App\Enums\SyncStatus;
use App\Models\Olt;
use App\Models\Onu;
use App\Models\SyncLog;
use App\Services\Olt\Cli\Contracts\CliProfile;
use App\Services\Olt\OltCollectorClient;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Runs the vendor CLI profile against an OLT through the collector and merges
 * the parsed optical power / customer MACs into the existing ONU rows.
 *
 * Only ONUs already known from the SNMP sync are updated (CLI never creates
 * rows); unmatched CLI rows are reported in the notes so command templates
 * can be tuned from the Diagnostics page.
 */
class OltCliEnrichService
{
    public function __construct(private readonly OltCollectorClient $collector) {}

    public function profileFor(Olt $olt): CliProfile
    {
        $class = config("olt.cli.vendors.{$olt->vendor}.profile");
        if (! $class || ! class_exists($class)) {
            throw new RuntimeException("No CLI profile is configured for vendor '{$olt->vendor}' (config/olt.php cli.vendors).");
        }

        return app($class);
    }

    /**
     * Run the full CLI pass. When $save is false nothing is written (dry run
     * for diagnostics). Returns the parsed result; throws on transport errors.
     */
    public function collect(Olt $olt): CliEnrichResult
    {
        if (! filled($olt->ssh_username) || ! filled($olt->ssh_password)) {
            throw new RuntimeException('CLI username/password are not set for this OLT.');
        }

        $profile = $this->profileFor($olt);
        $ports = $olt->ports()->orderBy('port_index')->get();
        if ($ports->isEmpty()) {
            throw new RuntimeException('No PON ports known yet — run an SNMP sync first so the CLI pass knows which ports to query.');
        }

        $commands = $profile->commands($olt, $ports);
        if (empty($commands)) {
            throw new RuntimeException('The CLI profile produced no commands for this OLT (check port names / command templates).');
        }

        $response = $this->collector->run(
            $olt,
            $commands,
            $profile->prepCommands($olt),
            $profile->transportOptions($olt),
        );

        $outputs = $response['outputs'] ?? [];
        $result = $profile->parse($olt, $ports, $outputs);
        array_unshift($result->notes, sprintf(
            'Collector ran %d command(s) over %s in %s ms (prompt: %s).',
            count($outputs),
            strtoupper($olt->cli_protocol ?? 'ssh'),
            $response['duration_ms'] ?? '?',
            $response['prompt'] ?? '?',
        ));
        if (! empty($response['login_log'])) {
            $result->loginLog = (string) $response['login_log'];
        }

        return $result;
    }

    /**
     * Collect + persist, recording a SyncLog (type "cli") and the OLT's CLI
     * status fields. Always returns the log, even on failure.
     */
    public function enrich(Olt $olt, string $trigger = 'schedule', ?int $userId = null): SyncLog
    {
        $log = $olt->syncLogs()->create([
            'type' => 'cli',
            'trigger' => $trigger,
            'status' => SyncStatus::Running,
            'triggered_by' => $userId,
            'started_at' => now(),
            'message' => 'Logging in to the OLT CLI…',
        ]);
        $started = microtime(true);

        try {
            $result = $this->collect($olt);
            $stats = $this->persist($olt, $result);
            $c = $result->counts();
            $message = sprintf(
                'CLI: optical for %d ONU(s), MACs for %d ONU(s); %d rows updated.',
                $c['optical'], $c['mac'], $stats['updated'],
            );
            $stats['notes'] = array_slice($result->notes, 0, 30);

            return $this->finish($olt, $log, SyncStatus::Success, $message, $stats, $started);
        } catch (Throwable $e) {
            return $this->finish($olt, $log, SyncStatus::Failed, mb_substr($e->getMessage(), 0, 1500), [], $started);
        }
    }

    /**
     * Write parsed rows onto the OLT's ONUs. Returns ['updated' => n, 'unmatched' => n].
     */
    public function persist(Olt $olt, CliEnrichResult $result): array
    {
        if (empty($result->rows)) {
            return ['updated' => 0, 'unmatched' => 0];
        }

        $now = now();
        $updated = 0;
        $unmatched = 0;

        // Index the OLT's ONUs by "<port_id>:<ont_id>" where ont_id is the last
        // segment of onu_index (Huawei "portIf.ont") or of the name ("GPON 0/1/0:5").
        $onus = $olt->onus()->get(['id', 'olt_port_id', 'onu_index', 'name', 'onu_mac', 'serial_number']);
        $byKey = [];
        foreach ($onus as $onu) {
            foreach (self::ontIdsFor($onu) as $ontId) {
                if ($onu->olt_port_id) {
                    $byKey[CliEnrichResult::key($onu->olt_port_id, $ontId)] = $onu;
                }
            }
        }

        DB::transaction(function () use ($result, $byKey, $now, &$updated, &$unmatched) {
            foreach ($result->rows as $key => $row) {
                $onu = $byKey[$key] ?? null;
                if (! $onu) {
                    $unmatched++;

                    continue;
                }

                $attrs = ['cli_synced_at' => $now];
                foreach (['rx_power', 'tx_power', 'olt_rx_power', 'distance'] as $f) {
                    if (array_key_exists($f, $row) && $row[$f] !== null) {
                        $attrs[$f] = $row[$f];
                    }
                }
                if (! empty($row['macs'])) {
                    $own = array_filter([$onu->onu_mac, self::macOrNull($onu->serial_number)]);
                    $macs = array_values(array_filter($row['macs'], fn ($m) => ! in_array($m, $own, true)));
                    if ($macs) {
                        $attrs['mac_address'] = $macs[0];
                        $attrs['mac_count'] = count($macs);
                        $attrs['mac_source'] = 'cli';
                    }
                }
                if (! empty($row['serial'])) {
                    $attrs['serial_number'] = strtoupper($row['serial']);
                }
                if (! empty($row['description'])) {
                    $attrs['description'] = $row['description'];
                }

                Onu::whereKey($onu->id)->update($attrs);
                $updated++;
            }
        });

        if ($unmatched) {
            $result->note("{$unmatched} parsed row(s) did not match a known ONU (port/ONT id).");
        }

        return ['updated' => $updated, 'unmatched' => $unmatched];
    }

    /** Candidate ONT ids for an ONU row: from onu_index ("…​.5") and from name ("…:5"). */
    public static function ontIdsFor(Onu $onu): array
    {
        $ids = [];
        if (preg_match('/\.(\d+)$/', (string) $onu->onu_index, $m)) {
            $ids[] = (int) $m[1];
        }
        if (preg_match('/:(\d+)$/', (string) $onu->name, $m)) {
            $ids[] = (int) $m[1];
        }

        return array_values(array_unique($ids));
    }

    private static function macOrNull(?string $raw): ?string
    {
        if (! $raw) {
            return null;
        }
        $hex = preg_replace('/[^0-9A-Fa-f]/', '', $raw);

        return strlen($hex) === 12 ? strtoupper(implode(':', str_split($hex, 2))) : null;
    }

    private function finish(Olt $olt, SyncLog $log, SyncStatus $status, string $message, array $stats, float $started): SyncLog
    {
        $durationMs = (int) round((microtime(true) - $started) * 1000);

        $log->update([
            'status' => $status,
            'message' => $message,
            'stats' => $stats,
            'duration_ms' => $durationMs,
            'finished_at' => now(),
        ]);

        $olt->forceFill([
            'cli_last_status' => $status->value,
            'cli_last_message' => mb_substr($message, 0, 2000),
            'cli_last_synced_at' => now(),
        ])->save();

        return $log;
    }
}
