<?php

namespace App\Jobs;

use App\Console\Commands\SnmpDebugCommand;
use App\Models\DiagnosticRun;
use App\Services\Olt\Cli\OltCliEnrichService;
use App\Services\Olt\Data\OnuInfo;
use App\Services\Olt\Drivers\AbstractVendorDriver;
use App\Services\Olt\OltCollectorClient;
use App\Services\Olt\VendorDriverManager;
use App\Services\Snmp\SnmpClient;
use App\Services\Snmp\SnmpProbeCatalog;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Executes one DiagnosticRun (created from the Diagnostics page) and stores
 * the raw output so OID maps / CLI parsers can be tuned from the browser.
 *
 * Kinds:
 *   snmp    walk a custom OID or the vendor's probe catalogue
 *   fetch   dry-run the SNMP driver (what the sync would store, + notes)
 *   cli     run arbitrary commands through the collector, return raw text
 *   enrich  run the vendor CLI profile; optionally save the parsed values
 */
class RunDiagnosticJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 1700;

    public int $tries = 1;

    public function __construct(public int $runId)
    {
        $this->onQueue(config('olt.sync.queue', 'olt-sync'));
    }

    public function handle(): void
    {
        $run = DiagnosticRun::with('olt')->find($this->runId);
        if (! $run || ! $run->olt) {
            return;
        }

        $run->update(['status' => 'running', 'started_at' => now()]);
        $started = microtime(true);

        try {
            [$output, $summary] = match ($run->kind) {
                DiagnosticRun::KIND_SNMP => $this->snmp($run),
                'fetch' => $this->fetch($run),
                DiagnosticRun::KIND_CLI => $this->cli($run),
                DiagnosticRun::KIND_ENRICH => $this->enrich($run),
                default => throw new \InvalidArgumentException("Unknown diagnostic kind '{$run->kind}'."),
            };

            $run->update([
                'status' => 'success',
                'output' => $this->cap($output),
                'summary' => mb_substr($summary, 0, 250),
            ]);
            $this->saveCopy($run, $output);
        } catch (Throwable $e) {
            $run->update([
                'status' => 'failed',
                'summary' => mb_substr($e->getMessage(), 0, 250),
                'output' => $this->cap(($run->output ? $run->output."\n\n" : '').'ERROR: '.$e->getMessage()),
            ]);
        } finally {
            $run->update([
                'duration_ms' => (int) round((microtime(true) - $started) * 1000),
                'finished_at' => now(),
            ]);
        }
    }

    /** @return array{0: string, 1: string} */
    private function snmp(DiagnosticRun $run): array
    {
        $olt = $run->olt;
        $p = $run->params ?? [];
        $limit = (int) ($p['limit'] ?? 50);

        if ($olt->shouldSimulate()) {
            throw new \RuntimeException('This OLT is in simulation mode — there is no real device to walk.');
        }

        $trees = [];
        if (! empty($p['oid'])) {
            $trees['Custom '.$p['oid']] = trim((string) $p['oid']);
        } elseif (! empty($p['preset']) && $p['preset'] !== 'all') {
            $catalog = SnmpProbeCatalog::all($olt->vendor);
            if (! isset($catalog[$p['preset']])) {
                throw new \RuntimeException("Unknown preset '{$p['preset']}'.");
            }
            $trees[$p['preset']] = $catalog[$p['preset']];
        } else {
            $trees = SnmpProbeCatalog::all($olt->vendor);
        }

        $client = SnmpClient::forOlt(
            $olt,
            ! empty($p['timeout']) ? (int) $p['timeout'] * 1_000_000 : null,
            null,
            ! empty($p['max_repetitions']) ? (int) $p['max_repetitions'] : null,
        );

        $lines = [
            "SNMP Diagnostics — OLT #{$olt->id} {$olt->name} ({$olt->ip_address})",
            "Vendor: {$olt->vendor}  Model: {$olt->model}  PON: ".($olt->pon_type?->value ?? 'auto'),
            'Generated: '.now()->toDateTimeString(),
            str_repeat('=', 80),
        ];
        $total = 0;
        $nonEmpty = 0;
        foreach ($trees as $label => $oid) {
            $block = SnmpDebugCommand::renderWalk($client, $label, $oid, $limit);
            $lines[] = '';
            $lines[] = $block;
            if (preg_match('/Row count: (\d+)/', $block, $m)) {
                $total += (int) $m[1];
                if ((int) $m[1] > 0) {
                    $nonEmpty++;
                }
            }
            // Stream partial output so long catalogue walks are visible early.
            $run->update(['output' => $this->cap(implode("\n", $lines))]);
        }
        $client->close();

        return [implode("\n", $lines), count($trees).' table(s) walked, '.$nonEmpty.' with data, '.$total.' rows total'];
    }

    /** @return array{0: string, 1: string} */
    private function fetch(DiagnosticRun $run): array
    {
        $olt = $run->olt;
        $limit = (int) (($run->params['limit'] ?? 40) ?: 40);
        $driver = app(VendorDriverManager::class)->for($olt);

        $t = microtime(true);
        $ports = $driver->fetchPorts($olt);
        $onus = $driver->fetchOnus($olt);
        $ms = (int) round((microtime(true) - $t) * 1000);

        $lines = [
            "Driver dry-run — OLT #{$olt->id} {$olt->name} ({$olt->ip_address}) via ".class_basename($driver),
            "Vendor: {$olt->vendor}  PON: ".($olt->pon_type?->value ?? 'auto')."  Took {$ms} ms",
            str_repeat('=', 80),
            '',
            'PON ports ('.count($ports).'):',
        ];
        foreach (array_slice($ports, 0, 64) as $port) {
            $lines[] = sprintf('    [%s] %s  admin=%s oper=%s', $port->portIndex, $port->name, $port->adminStatus, $port->operStatus);
        }

        if ($driver instanceof AbstractVendorDriver && $driver->notes) {
            $lines[] = '';
            $lines[] = 'Driver notes:';
            foreach ($driver->notes as $n) {
                $lines[] = '    · '.$n;
            }
        }

        $with = fn (callable $f) => count(array_filter($onus, $f));
        $lines[] = '';
        $lines[] = sprintf(
            'ONUs: %d total · %d online · %d with serial · %d with rx/tx · %d with OLT-rx · %d with distance · %d with router MAC · %d with ONU MAC · %d with model',
            count($onus),
            $with(fn (OnuInfo $o) => $o->status->value === 'online'),
            $with(fn (OnuInfo $o) => $o->serialNumber !== null),
            $with(fn (OnuInfo $o) => $o->rxPower !== null || $o->txPower !== null),
            $with(fn (OnuInfo $o) => $o->oltRxPower !== null),
            $with(fn (OnuInfo $o) => $o->distance !== null),
            $with(fn (OnuInfo $o) => $o->macAddress !== null),
            $with(fn (OnuInfo $o) => $o->onuMac !== null),
            $with(fn (OnuInfo $o) => $o->model !== null),
        );
        $lines[] = '';
        $lines[] = sprintf('%-18s %-16s %-8s %-14s %-18s %-7s %-7s %-7s %-7s %-18s %-5s %-12s %s',
            'index', 'name', 'status', 'serial', 'router MAC', 'rx', 'tx', 'oltRx', 'dist', 'ONU MAC', 'macs', 'model', 'online since / last down');
        foreach (array_slice($onus, 0, $limit) as $o) {
            $lines[] = sprintf('%-18s %-16s %-8s %-14s %-18s %-7s %-7s %-7s %-7s %-18s %-5s %-12s %s',
                $o->onuIndex, mb_substr((string) $o->name, 0, 16), $o->status->value, mb_substr((string) $o->serialNumber, 0, 14),
                $o->macAddress ?? '-', $o->rxPower ?? '-', $o->txPower ?? '-', $o->oltRxPower ?? '-', $o->distance ?? '-',
                $o->onuMac ?? '-', $o->macCount ?? '-', mb_substr((string) $o->model, 0, 12),
                ($o->onlineSince?->toDateTimeString() ?? '-').' / '.($o->lastDownAt?->toDateTimeString() ?? '-').($o->lastDownCause ? " ({$o->lastDownCause})" : ''),
            );
        }
        if (count($onus) > $limit) {
            $lines[] = '… '.(count($onus) - $limit).' more ONUs (raise the limit to see all)';
        }

        return [implode("\n", $lines), count($onus).' ONUs, '.$with(fn (OnuInfo $o) => $o->rxPower !== null).' with optical, '.$with(fn (OnuInfo $o) => $o->macAddress !== null).' with MAC'];
    }

    /** @return array{0: string, 1: string} */
    private function cli(DiagnosticRun $run): array
    {
        $olt = $run->olt;
        $p = $run->params ?? [];
        $commands = array_values(array_filter(array_map('trim', (array) ($p['commands'] ?? []))));
        if (empty($commands)) {
            throw new \RuntimeException('No commands given.');
        }

        $collector = app(OltCollectorClient::class);
        $prep = [];
        if (! empty($p['prep'])) {
            $prep = (array) config("olt.cli.vendors.{$olt->vendor}.prep", []);
        }
        $options = array_filter([
            'protocol' => $p['protocol'] ?? null,
            'port' => ! empty($p['port']) ? (int) $p['port'] : null,
            'char_delay' => $p['char_delay'] ?? null,
            'command_timeout' => $p['command_timeout'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');

        $res = $collector->run($olt, $commands, $prep, $options);

        $lines = [
            "CLI Diagnostics — OLT #{$olt->id} {$olt->name} ({$olt->ip_address}) via ".strtoupper($options['protocol'] ?? $olt->cli_protocol ?? 'ssh'),
            'Prompt detected: '.($res['prompt'] ?? '?').'   Total: '.($res['duration_ms'] ?? '?').' ms',
            str_repeat('=', 80),
        ];
        if (! empty($res['login_log'])) {
            $lines[] = '';
            $lines[] = '--- login / prep transcript ---';
            $lines[] = rtrim((string) $res['login_log']);
        }
        foreach ($res['outputs'] ?? [] as $out) {
            $lines[] = '';
            $lines[] = '### '.$out['command'].(isset($out['duration_ms']) ? "   ({$out['duration_ms']} ms)" : '').(! empty($out['error']) ? '   ERROR: '.$out['error'] : '');
            $lines[] = rtrim((string) $out['output']);
        }

        return [implode("\n", $lines), count($res['outputs'] ?? []).' command(s) executed'];
    }

    /** @return array{0: string, 1: string} */
    private function enrich(DiagnosticRun $run): array
    {
        $olt = $run->olt;
        $save = (bool) ($run->params['save'] ?? false);
        $service = app(OltCliEnrichService::class);

        $result = $service->collect($olt);
        $stats = $save ? $service->persist($olt, $result) : ['updated' => 0, 'unmatched' => 0];

        $lines = [
            "CLI enrichment ".($save ? '(SAVED)' : '(dry run)')." — OLT #{$olt->id} {$olt->name} via ".strtoupper($olt->cli_protocol ?? 'ssh'),
            str_repeat('=', 80),
            '',
            'Notes:',
        ];
        foreach ($result->notes as $n) {
            $lines[] = '    · '.$n;
        }
        if ($save) {
            $lines[] = "    · Saved: {$stats['updated']} ONU row(s) updated, {$stats['unmatched']} unmatched.";
        }

        $lines[] = '';
        $lines[] = 'Parsed rows ('.count($result->rows).'):';
        $lines[] = sprintf('%-9s %-7s %-8s %-8s %-8s %s', 'port_id', 'ont', 'rx', 'tx', 'oltRx', 'macs');
        foreach (array_slice($result->rows, 0, 400) as $row) {
            $lines[] = sprintf('%-9s %-7s %-8s %-8s %-8s %s',
                $row['port_id'], $row['ont_id'], $row['rx_power'] ?? '-', $row['tx_power'] ?? '-', $row['olt_rx_power'] ?? '-',
                implode(', ', $row['macs'] ?? []));
        }

        if ($result->loginLog) {
            $lines[] = '';
            $lines[] = '--- login / prep transcript ---';
            $lines[] = rtrim($result->loginLog);
        }
        foreach ($result->outputs as $out) {
            $lines[] = '';
            $lines[] = '### '.$out['command'].(isset($out['duration_ms']) ? "   ({$out['duration_ms']} ms)" : '').(! empty($out['error']) ? '   ERROR: '.$out['error'] : '');
            $lines[] = rtrim((string) $out['output']);
        }

        $c = $result->counts();

        return [implode("\n", $lines), "optical for {$c['optical']} ONU(s), MACs for {$c['mac']} ONU(s)".($save ? ", {$stats['updated']} saved" : ' (dry run)')];
    }

    private function cap(string $text): string
    {
        $max = 4_000_000; // stay well under MySQL LONGTEXT / memory limits
        if (strlen($text) <= $max) {
            return $text;
        }

        return substr($text, 0, $max)."\n\n… output truncated (".strlen($text).' bytes)';
    }

    /** Also keep a file copy under dev_resources/debug (same as the CLI debug commands). */
    private function saveCopy(DiagnosticRun $run, string $output): void
    {
        try {
            $dir = base_path('dev_resources/debug');
            if (! is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            file_put_contents($dir.DIRECTORY_SEPARATOR."diag_{$run->kind}_olt{$run->olt_id}_".now()->format('Ymd_His')."_{$run->id}.txt", $output);
        } catch (Throwable) {
            // best effort only
        }
    }
}
