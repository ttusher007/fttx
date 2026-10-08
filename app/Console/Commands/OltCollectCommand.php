<?php

namespace App\Console\Commands;

use App\Models\Olt;
use App\Services\Olt\Cli\OltCliEnrichService;
use App\Services\Olt\OltCollectorClient;
use Illuminate\Console\Command;
use Throwable;

/**
 * Calls the Python SSH/Telnet collector from Laravel. A discovery/test tool:
 * runs raw commands, or the vendor CLI enrichment profile, against an OLT and
 * saves the output to dev_resources/debug/. The same actions are available in
 * the browser on the Diagnostics page.
 *
 * Examples:
 *   php artisan olt:collect 10 --raw="display version" --protocol=telnet
 *   php artisan olt:collect 10 --raw="display board 0" --raw="display ont info 0/1/0 all" --prep
 *   php artisan olt:collect 10 --enrich            # dry run of the CLI profile
 *   php artisan olt:collect 10 --enrich --save     # and write the parsed values
 */
class OltCollectCommand extends Command
{
    protected $signature = 'olt:collect
        {olt : OLT id or IP address}
        {--raw=* : Run these CLI commands (repeatable) and print the raw text}
        {--prep : Also run the vendor prep commands (enable, disable paging…) before --raw commands}
        {--enrich : Run the vendor CLI enrichment profile (optical + MACs) and show the parsed rows}
        {--save : With --enrich, persist the parsed values to the ONUs}
        {--protocol= : ssh or telnet (default: the OLT\'s cli_protocol)}
        {--port= : Override the TCP port}';

    protected $description = 'Hit the Python OLT collector (SSH/Telnet) for raw output or the CLI enrichment profile.';

    public function handle(OltCollectorClient $collector, OltCliEnrichService $enrich): int
    {
        $arg = $this->argument('olt');
        $olt = is_numeric($arg)
            ? Olt::findOrFail((int) $arg)
            : Olt::where('ip_address', $arg)->firstOrFail();

        $protocol = $this->option('protocol') ?: ($olt->cli_protocol ?: 'ssh');
        $port = $this->option('port') !== null ? (int) $this->option('port') : null;

        $this->info("OLT: {$olt->name} ({$olt->ip_address}) via {$protocol}");

        if (! $collector->healthy()) {
            $this->error('Collector not reachable at '.config('services.olt_collector.url').
                ' — is the Python service running? (systemctl status olt-collector)');

            return self::FAILURE;
        }

        $text = '';
        $label = 'raw';

        try {
            if ($this->option('enrich')) {
                $label = 'enrich';
                $result = $enrich->collect($olt);
                $stats = $this->option('save') ? $enrich->persist($olt, $result) : null;

                foreach ($result->notes as $note) {
                    $this->line("  · {$note}");
                }
                if ($stats) {
                    $this->info("Saved: {$stats['updated']} ONU rows updated, {$stats['unmatched']} unmatched.");
                }
                if ($result->rows) {
                    $this->table(['port_id', 'ont', 'rx', 'tx', 'oltRx', 'macs'], array_map(fn ($r) => [
                        $r['port_id'], $r['ont_id'], $r['rx_power'] ?? '—', $r['tx_power'] ?? '—', $r['olt_rx_power'] ?? '—', implode(', ', $r['macs'] ?? []),
                    ], array_slice($result->rows, 0, 50)));
                }
                $text = json_encode(['notes' => $result->notes, 'rows' => $result->rows, 'outputs' => $result->outputs, 'login_log' => $result->loginLog], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            } else {
                $commands = array_values(array_filter((array) $this->option('raw')));
                if (empty($commands)) {
                    throw new \InvalidArgumentException('Pass --raw="<command>" (repeatable) or --enrich.');
                }
                $prep = $this->option('prep') ? (array) config("olt.cli.vendors.{$olt->vendor}.prep", []) : [];
                $res = $collector->run($olt, $commands, $prep, ['protocol' => $protocol, 'port' => $port]);

                $this->line('Prompt: '.($res['prompt'] ?? '?').'  total '.($res['duration_ms'] ?? '?').' ms');
                foreach ($res['outputs'] ?? [] as $out) {
                    $this->newLine();
                    $this->comment('### '.$out['command'].(! empty($out['error']) ? '  ERROR: '.$out['error'] : ''));
                    $this->line($out['output']);
                }
                $text = json_encode($res, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            }
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $outDir = base_path('dev_resources/debug');
        if (! is_dir($outDir)) {
            mkdir($outDir, 0755, true);
        }
        $file = $outDir.DIRECTORY_SEPARATOR."collect_{$label}_olt{$olt->id}_".now()->format('Ymd_His').'.txt';
        file_put_contents($file, $text."\n");

        $this->info('Saved → dev_resources/debug/'.basename($file));

        return self::SUCCESS;
    }
}
