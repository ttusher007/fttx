<?php

namespace App\Console\Commands;

use App\Models\Olt;
use App\Services\Snmp\SnmpClient;
use App\Services\Snmp\SnmpProbeCatalog;
use Illuminate\Console\Command;

/**
 * Dumps raw SNMP walks from an OLT into dev_resources/debug/ so OID → value
 * mappings can be inspected and used to tune config/olt.php.
 *
 * The same probes are available from the browser on the Diagnostics page.
 *
 * Usage:
 *   php artisan olt:snmp-debug {olt_id}
 *   php artisan olt:snmp-debug {olt_id} --oid=1.3.6.1.4.1.3320.10.3.3.1
 */
class SnmpDebugCommand extends Command
{
    protected $signature = 'olt:snmp-debug
                            {olt : OLT id or IP address}
                            {--oid= : Walk a single custom OID subtree instead of the defaults}
                            {--limit=20 : Max rows to print per table (0 = unlimited)}';

    protected $description = 'Walk SNMP OID trees on an OLT and save raw output to dev_resources/debug/';

    public function handle(): int
    {
        $arg = $this->argument('olt');
        $olt = is_numeric($arg)
            ? Olt::findOrFail((int) $arg)
            : Olt::where('ip_address', $arg)->firstOrFail();

        $this->info("OLT: {$olt->name} ({$olt->ip_address})  vendor={$olt->vendor}  model={$olt->model}");

        if ($olt->shouldSimulate()) {
            $this->error('This OLT is in simulation mode — no real SNMP to walk.');

            return 1;
        }

        $client = SnmpClient::forOlt($olt);
        $limit = (int) $this->option('limit');

        if ($customOid = $this->option('oid')) {
            $trees = ['Custom' => $customOid];
        } else {
            $trees = SnmpProbeCatalog::all($olt->vendor);
        }

        $lines = [];
        $lines[] = "SNMP Debug — OLT #{$olt->id} {$olt->name} ({$olt->ip_address})";
        $lines[] = "Vendor: {$olt->vendor}  Model: {$olt->model}";
        $lines[] = 'Generated: '.now()->toDateTimeString();
        $lines[] = str_repeat('=', 80);

        foreach ($trees as $label => $baseOid) {
            $this->line("  Walking [{$label}]  {$baseOid} …");
            $lines[] = '';
            $lines[] = self::renderWalk($client, $label, $baseOid, $limit);
        }

        $client->close();

        $outDir = base_path('dev_resources/debug');
        if (! is_dir($outDir)) {
            mkdir($outDir, 0755, true);
        }

        $filename = "snmp_debug_olt{$olt->id}_".now()->format('Ymd_His').'.txt';
        $path = $outDir.DIRECTORY_SEPARATOR.$filename;

        file_put_contents($path, implode("\n", $lines)."\n");

        $this->info("Saved → dev_resources/debug/{$filename}");

        return 0;
    }

    /**
     * Walk one subtree and render it as a text block (shared with the
     * Diagnostics page so both produce identical dumps).
     */
    public static function renderWalk(SnmpClient $client, string $label, string $baseOid, int $limit = 20): string
    {
        $started = microtime(true);
        $rows = $client->walk($baseOid);
        $ms = (int) round((microtime(true) - $started) * 1000);

        $lines = [];
        $lines[] = "### {$label}";
        $lines[] = "    Base OID : {$baseOid}";
        $lines[] = '    Row count: '.count($rows)."    ({$ms} ms)";
        if ($client->lastError) {
            $lines[] = '    Error    : '.$client->lastError;
        }
        $lines[] = '';

        if (empty($rows)) {
            $lines[] = '    (no data returned)';

            return implode("\n", $lines);
        }

        $count = 0;
        foreach ($rows as $index => $value) {
            $lines[] = sprintf('    [%s]  =>  %s', $index, $value);
            $count++;
            if ($limit > 0 && $count >= $limit) {
                $remaining = count($rows) - $limit;
                if ($remaining > 0) {
                    $lines[] = "    … {$remaining} more rows (increase the limit to see all)";
                }
                break;
            }
        }

        return implode("\n", $lines);
    }
}
