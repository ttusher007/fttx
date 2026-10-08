<?php

namespace App\Services\Olt\Cli\Profiles;

use App\Models\Olt;
use App\Models\OltPort;
use App\Services\Olt\Cli\CliEnrichResult;
use Illuminate\Support\Collection;

/**
 * Best-effort profile for vendors whose CLI tables we have not yet seen on
 * real hardware (BDCOM, VSOL). It runs the configured per-port commands and
 * parses rows of the form "<onu-id> … <rx dBm> <tx dBm> [olt-rx]" and any
 * "<onu-id> … <mac>" lines. Use the Diagnostics page to look at the raw
 * output and tighten the command templates in config/olt.php (cli.vendors.*).
 */
abstract class GenericTableCliProfile extends AbstractCliProfile
{
    public function commands(Olt $olt, Collection $ports): array
    {
        $commands = [];
        $optical = (string) $this->cfg('optical', '');
        $mac = (string) $this->cfg('mac', '');

        foreach ($ports as $port) {
            /** @var OltPort $port */
            $vars = $this->vars($port);
            if ($optical !== '') {
                $commands[] = $this->fill($optical, $vars);
            }
            if ($mac !== '') {
                $commands[] = $this->fill($mac, $vars);
            }
        }

        return $commands;
    }

    public function parse(Olt $olt, Collection $ports, array $outputs): CliEnrichResult
    {
        $result = new CliEnrichResult;
        $result->outputs = $outputs;

        // Commands were generated per port in order; rebuild the mapping.
        $expected = [];
        $optical = (string) $this->cfg('optical', '');
        $mac = (string) $this->cfg('mac', '');
        foreach ($ports as $port) {
            $vars = $this->vars($port);
            if ($optical !== '') {
                $expected[$this->fill($optical, $vars)] = [$port, 'optical'];
            }
            if ($mac !== '') {
                $expected[$this->fill($mac, $vars)] = [$port, 'mac'];
            }
        }

        foreach ($outputs as $out) {
            $cmd = trim((string) ($out['command'] ?? ''));
            $text = (string) ($out['output'] ?? '');
            if (! isset($expected[$cmd])) {
                continue;
            }
            [$port, $kind] = $expected[$cmd];

            if ($kind === 'optical') {
                $n = 0;
                foreach (preg_split('/\r\n|\r|\n/', $text) ?: [] as $line) {
                    // "<id or GPON0/1:5>  …  -19.3  2.4  [-22.1]"
                    if (! preg_match('/^\s*(?:\S*?:)?(\d+)\b.*?(-\d+\.\d+)\s+(-?\d+\.\d+)(?:\s+(-\d+\.\d+))?/', $line, $m)) {
                        continue;
                    }
                    $result->merge($port->id, (int) $m[1], [
                        'rx_power' => self::dbm($m[2]),
                        'tx_power' => self::dbm($m[3]),
                        'olt_rx_power' => isset($m[4]) ? self::dbm($m[4]) : null,
                    ]);
                    $n++;
                }
                if (! $n) {
                    $result->note("{$cmd}: no optical rows parsed");
                }
            } else {
                $n = 0;
                foreach (preg_split('/\r\n|\r|\n/', $text) ?: [] as $line) {
                    if (! preg_match('/([0-9A-Fa-f]{4}[\.\-][0-9A-Fa-f]{4}[\.\-][0-9A-Fa-f]{4}|(?:[0-9A-Fa-f]{2}[:\-]){5}[0-9A-Fa-f]{2})/', $line, $mm)) {
                        continue;
                    }
                    // ONU id as ":<n>" (GPON0/1:5) or "ONU <n>" or a bare trailing integer.
                    $ontId = null;
                    if (preg_match('/:(\d+)\b/', $line, $m) || preg_match('/\bONU\s*(\d+)\b/i', $line, $m)) {
                        $ontId = (int) $m[1];
                    }
                    $mac = self::mac($mm[1]);
                    if ($ontId === null || ! $mac) {
                        continue;
                    }
                    $result->merge($port->id, $ontId, ['macs' => [$mac]]);
                    $n++;
                }
                if (! $n) {
                    $result->note("{$cmd}: no MAC rows parsed");
                }
            }
        }

        $c = $result->counts();
        $result->note("Parsed optical for {$c['optical']} ONU(s), MACs for {$c['mac']} ONU(s) (generic parser — verify on Diagnostics).");

        return $result;
    }

    private function vars(OltPort $port): array
    {
        $path = self::portPath($port->name) ?? '';
        $num = preg_match('/(\d+)\s*$/', $path, $m) ? $m[1] : '';

        return [
            'ifname' => (string) $port->name,
            'fsp' => $path,
            'path' => $path,
            'port' => $num,
        ];
    }
}
