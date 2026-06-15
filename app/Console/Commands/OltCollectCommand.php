<?php

namespace App\Console\Commands;

use App\Models\Olt;
use App\Services\Olt\OltCollectorClient;
use Illuminate\Console\Command;
use Throwable;

/**
 * Calls the Python SSH/Telnet collector from Laravel. Mirrors olt:snmp-debug:
 * a discovery/test tool that runs a command (or the parsed optical/mac jobs)
 * against an OLT and saves the output to dev_resources/debug/.
 *
 * Examples:
 *   php artisan olt:collect 10 --raw="display version" --protocol=telnet
 *   php artisan olt:collect 10 --optical --fsp=0/1/0 --protocol=telnet
 *   php artisan olt:collect 10 --mac --protocol=telnet
 */
class OltCollectCommand extends Command
{
    protected $signature = 'olt:collect
        {olt : OLT id or IP address}
        {--raw= : Run this CLI command on the OLT and print the raw text}
        {--optical : Fetch parsed ONT optical power}
        {--mac : Fetch parsed CPE/user MAC addresses}
        {--protocol=ssh : ssh or telnet}
        {--port= : Override the TCP port (default 22 ssh / 23 telnet)}
        {--fsp= : Frame/slot/port for Huawei, e.g. 0/1/0}
        {--ont= : ONT id (optional, narrows to one ONU)}';

    protected $description = 'Hit the Python OLT collector (SSH/Telnet) for raw output, optical power, or MACs.';

    public function handle(OltCollectorClient $collector): int
    {
        $arg = $this->argument('olt');
        $olt = is_numeric($arg)
            ? Olt::findOrFail((int) $arg)
            : Olt::where('ip_address', $arg)->firstOrFail();

        $protocol = $this->option('protocol') === 'telnet' ? 'telnet' : 'ssh';
        $port = $this->option('port') !== null ? (int) $this->option('port') : null;
        $fsp = $this->option('fsp');
        $ont = $this->option('ont') !== null ? (int) $this->option('ont') : null;

        $this->info("OLT: {$olt->name} ({$olt->ip_address}) via {$protocol}");

        if (! $collector->healthy()) {
            $this->error('Collector not reachable at '.config('services.olt_collector.url').
                ' — is the Python service running? (systemctl status olt-collector)');

            return self::FAILURE;
        }

        try {
            [$label, $result] = match (true) {
                $this->option('optical') => ['optical', $collector->optical($olt, $fsp, $ont, $protocol, $port)],
                $this->option('mac') => ['mac', $collector->mac($olt, $fsp, $ont, $protocol, $port)],
                (bool) $this->option('raw') => ['raw', $collector->raw($olt, (string) $this->option('raw'), $protocol, $port)],
                default => throw new \InvalidArgumentException('Pass one of --raw="…", --optical, or --mac.'),
            };
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        // Show parsed rows as a table when present.
        if (! empty($result['rows'])) {
            $rows = $result['rows'];
            $this->table(array_keys((array) $rows[0]), array_map(fn ($r) => array_map(
                fn ($v) => is_null($v) ? '—' : (string) $v, (array) $r
            ), $rows));
        }

        // Persist the full payload (incl. raw text) for inspection / sharing.
        $outDir = base_path('dev_resources/debug');
        if (! is_dir($outDir)) {
            mkdir($outDir, 0755, true);
        }
        $file = $outDir.DIRECTORY_SEPARATOR."collect_{$label}_olt{$olt->id}_".now()->format('Ymd_His').'.txt';
        file_put_contents($file, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

        $this->info('Saved → dev_resources/debug/'.basename($file));

        return self::SUCCESS;
    }
}
