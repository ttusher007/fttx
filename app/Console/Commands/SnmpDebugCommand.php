<?php

namespace App\Console\Commands;

use App\Models\Olt;
use App\Services\Snmp\SnmpClient;
use Illuminate\Console\Command;

/**
 * Dumps raw SNMP walks from an OLT into dev_resources/debug/ so OID → value
 * mappings can be inspected and used to tune config/olt.php.
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
        $limit  = (int) $this->option('limit');

        if ($customOid = $this->option('oid')) {
            $trees = ['Custom' => $customOid];
        } else {
            $trees = $this->defaultTrees($olt->vendor);
        }

        $lines   = [];
        $lines[] = "SNMP Debug — OLT #{$olt->id} {$olt->name} ({$olt->ip_address})";
        $lines[] = "Vendor: {$olt->vendor}  Model: {$olt->model}";
        $lines[] = 'Generated: '.now()->toDateTimeString();
        $lines[] = str_repeat('=', 80);

        foreach ($trees as $label => $baseOid) {
            $this->line("  Walking [{$label}]  {$baseOid} …");

            $rows = $client->walk($baseOid);

            $lines[] = '';
            $lines[] = "### {$label}";
            $lines[] = "    Base OID : {$baseOid}";
            $lines[] = '    Row count: '.count($rows);
            $lines[] = '';

            if (empty($rows)) {
                $lines[] = '    (no data returned)';
                continue;
            }

            $count = 0;
            foreach ($rows as $index => $value) {
                $lines[] = sprintf('    [%s]  =>  %s', $index, $value);
                $count++;
                if ($limit > 0 && $count >= $limit) {
                    $remaining = count($rows) - $limit;
                    if ($remaining > 0) {
                        $lines[] = "    … {$remaining} more rows (increase --limit to see all)";
                    }
                    break;
                }
            }
        }

        $client->close();

        $outDir  = base_path('dev_resources/debug');
        if (! is_dir($outDir)) {
            mkdir($outDir, 0755, true);
        }

        $filename = "snmp_debug_olt{$olt->id}_".now()->format('Ymd_His').'.txt';
        $path     = $outDir.DIRECTORY_SEPARATOR.$filename;

        file_put_contents($path, implode("\n", $lines)."\n");

        $this->info("Saved → dev_resources/debug/{$filename}");

        return 0;
    }

    /**
     * Return the OID trees most likely to contain ONU identity / optical data
     * for the given vendor. Always includes a few generic/standard trees.
     *
     * For BDCOM, we walk each table column individually so the --limit applies
     * per-column and we can read actual values instead of just the first column.
     */
    private function defaultTrees(string $vendor): array
    {
        $common = [
            'IF-MIB ifDescr (interface names)'  => '1.3.6.1.2.1.2.2.1.2',
            'IF-MIB ifAlias (interface aliases)' => '1.3.6.1.2.1.31.1.1.1.18',
        ];

        $vendorTrees = match (strtolower($vendor)) {
            'bdcom' => [
                // ONU base table columns (1.3.6.1.4.1.3320.10.3.3.1.COL)
                'BDCOM base col.1  — ONU ifIndex'                => '1.3.6.1.4.1.3320.10.3.3.1.1',
                'BDCOM base col.2  — (serial / GPON-ID?)'        => '1.3.6.1.4.1.3320.10.3.3.1.2',
                'BDCOM base col.3  — (unknown)'                  => '1.3.6.1.4.1.3320.10.3.3.1.3',
                'BDCOM base col.4  — run_status'                 => '1.3.6.1.4.1.3320.10.3.3.1.4',
                'BDCOM base col.5  — (serial / password?)'       => '1.3.6.1.4.1.3320.10.3.3.1.5',
                'BDCOM base col.6  — (unknown)'                  => '1.3.6.1.4.1.3320.10.3.3.1.6',
                'BDCOM base col.7  — (uptime / online seconds?)' => '1.3.6.1.4.1.3320.10.3.3.1.7',
                'BDCOM base col.8  — (unknown)'                  => '1.3.6.1.4.1.3320.10.3.3.1.8',
                'BDCOM base col.9  — (unknown)'                  => '1.3.6.1.4.1.3320.10.3.3.1.9',
                'BDCOM base col.10 — (unknown)'                  => '1.3.6.1.4.1.3320.10.3.3.1.10',
                // ONU optical table columns (1.3.6.1.4.1.3320.10.3.4.1.COL)
                'BDCOM optical col.1 — ONU ifIndex'              => '1.3.6.1.4.1.3320.10.3.4.1.1',
                'BDCOM optical col.2 — rx_power'                 => '1.3.6.1.4.1.3320.10.3.4.1.2',
                'BDCOM optical col.3 — tx_power'                 => '1.3.6.1.4.1.3320.10.3.4.1.3',
                'BDCOM optical col.4 — (distance?)'              => '1.3.6.1.4.1.3320.10.3.4.1.4',
                'BDCOM optical col.5 — (unknown)'                => '1.3.6.1.4.1.3320.10.3.4.1.5',
                'BDCOM optical col.6 — (unknown)'                => '1.3.6.1.4.1.3320.10.3.4.1.6',
                // Alternative bridge/MAC table
                'BDCOM MAC bridge table (.3.5.1.*)'              => '1.3.6.1.4.1.3320.10.3.5.1',
                // Standard bridge MIB — may hold learned CPE MACs
                'dot1dTpFdbTable (bridge MAC table)'             => '1.3.6.1.2.1.17.4.3.1',
            ],
            'huawei' => [
                // IF-MIB ifName — gives real port names (e.g. "GPON 0/0/0"). Run with --limit=0.
                'ifName (real port names — use --limit=0)'           => '1.3.6.1.2.1.31.1.1.1.1',

                // ONU info table key columns
                'Huawei .43.1.3 — serial number'                    => '1.3.6.1.4.1.2011.6.128.1.1.2.43.1.3',
                'Huawei .43.1.9 — description'                      => '1.3.6.1.4.1.2011.6.128.1.1.2.43.1.9',

                // ONU control/status table
                'Huawei .46.1.15 — run_status'                      => '1.3.6.1.4.1.2011.6.128.1.1.2.46.1.15',
                'Huawei .46.1.20 — distance'                        => '1.3.6.1.4.1.2011.6.128.1.1.2.46.1.20',
                'Huawei .46.1.23 — online_since (DateAndTime hex)'  => '1.3.6.1.4.1.2011.6.128.1.1.2.46.1.23',
                // Optical DDM — hwGponOntOpticalDdmTable; returns 0 rows on MA5683T V800R018.
                // Will populate on V800R019+ firmware. Probing here to detect when it becomes available.
                'Huawei .51.1.* — DDM table (rx/tx power, V800R019+)' => '1.3.6.1.4.1.2011.6.128.1.1.2.51.1',
            ],
            'vsol' => [
                // VsolDriver enumerates ONUs from IF-MIB — these standard OIDs are the primary source.
                'ifOperStatus (ONU online/offline)'  => '1.3.6.1.2.1.2.2.1.8',
                'ifAdminStatus'                      => '1.3.6.1.2.1.2.2.1.7',
                // Vendor-specific tables for future investigation (serial/optical/distance).
                // The port-management table (.5.10.1.1) contains 12 GE-level entries, not GPON ONUs.
                // The optical table (.5.12.1.1) returned 0 rows on V2.1.16 — probe after firmware upgrade.
                'VSOL port-mgmt table (.5.10.1.1.*)' => '1.3.6.1.4.1.37950.1.1.5.10.1.1',
                'VSOL optical table (.5.12.1.1.*)'   => '1.3.6.1.4.1.37950.1.1.5.12.1.1',
            ],
            default => [],
        };

        return array_merge($common, $vendorTrees);
    }
}
