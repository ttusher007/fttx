<?php

namespace App\Services\Olt\Cli\Profiles;

use App\Models\Olt;
use App\Models\OltPort;
use App\Services\Olt\Cli\CliEnrichResult;
use Illuminate\Support\Collection;

/**
 * Huawei SmartAX MA5600T / MA5683T / MA5608T / MA5800 CLI profile.
 *
 * Session layout (all in ONE login):
 *   enable → undo smart / undo interactive / undo alarm output all → scroll 512 → config
 *   for each slot with GPON ports:
 *       interface gpon F/S
 *       display ont optical-info P all        (one per port in the slot)
 *       quit
 *   for each PON port:
 *       display mac-address port F/S/P        (customer MACs; VPI column = ONT ID)
 *
 * Optical output (per port) looks like:
 *   ONT-ID  Rx power(dBm)  Tx power(dBm)  OLT Rx ONT power(dBm)  Temperature(C) …
 *   0        -19.37         2.41           -22.80                 44
 *
 * MAC output looks like:
 *   SRV-P BUNDLE TYPE MAC            MAC TYPE F /S /P  VPI  VCI   VLAN ID
 *   INDEX INDEX
 *       0     -   gpon 00e0-fc12-3456 dynamic 0 /1 /0  1    1     100
 *
 * Both parsers are header-aware and tolerant to column reordering between
 * firmware versions.
 */
class HuaweiCliProfile extends AbstractCliProfile
{
    private const OPTICAL_MARK = 'optical:';

    private const MAC_MARK = 'mac:';

    protected function vendorKey(): string
    {
        return 'huawei';
    }

    public function transportOptions(Olt $olt): array
    {
        return array_merge([
            // Huawei prompts: MA5683T> / MA5683T(config)# / MA5683T(config-if-gpon-0/1)#
            'prompt_regex' => '[\w\-\.\(\)\/]*[>#]\s*$',
            'command_timeout' => 180,
        ], parent::transportOptions($olt));
    }

    public function commands(Olt $olt, Collection $ports): array
    {
        $commands = [];
        $bySlot = [];

        foreach ($ports as $port) {
            /** @var OltPort $port */
            $path = self::portPath($port->name);
            if (! $path || substr_count($path, '/') !== 2) {
                continue;
            }
            [$frame, $slot, $p] = explode('/', $path);
            $bySlot["{$frame}/{$slot}"][] = (int) $p;
        }

        $optical = (string) $this->cfg('optical', 'display ont optical-info {port} all');
        foreach ($bySlot as $fs => $portNumbers) {
            sort($portNumbers);
            $commands[] = "interface gpon {$fs}";
            foreach ($portNumbers as $p) {
                $commands[] = $this->fill($optical, ['port' => $p, 'fsp' => "{$fs}/{$p}"]);
            }
            $commands[] = 'quit';
        }

        if ($this->cfg('use_mac_all')) {
            $commands[] = (string) $this->cfg('mac_all', 'display mac-address all');
        } else {
            $mac = (string) $this->cfg('mac', 'display mac-address port {fsp}');
            foreach ($bySlot as $fs => $portNumbers) {
                foreach ($portNumbers as $p) {
                    $commands[] = $this->fill($mac, ['port' => $p, 'fsp' => "{$fs}/{$p}"]);
                }
            }
        }

        return $commands;
    }

    public function parse(Olt $olt, Collection $ports, array $outputs): CliEnrichResult
    {
        $result = new CliEnrichResult;
        $result->outputs = $outputs;
        $byPath = $this->portsByPath($ports);

        $currentSlot = null;
        foreach ($outputs as $out) {
            $cmd = trim((string) ($out['command'] ?? ''));
            $text = (string) ($out['output'] ?? '');

            if (! empty($out['error'])) {
                $result->note("{$cmd}: {$out['error']}");
            }

            if (preg_match('/^interface\s+gpon\s+(\d+\/\d+)/i', $cmd, $m)) {
                $currentSlot = $m[1];

                continue;
            }
            if (strcasecmp($cmd, 'quit') === 0) {
                $currentSlot = null;

                continue;
            }

            if (stripos($cmd, 'optical-info') !== false) {
                // "display ont optical-info 3 all" inside interface gpon 0/1 → port 0/1/3
                $fsp = null;
                if (preg_match('/optical-info\s+(\d+)\/(\d+)\/(\d+)/', $cmd, $m)) {
                    $fsp = "{$m[1]}/{$m[2]}/{$m[3]}";
                } elseif ($currentSlot && preg_match('/optical-info\s+(\d+)/', $cmd, $m)) {
                    $fsp = "{$currentSlot}/{$m[1]}";
                }
                $port = $fsp ? ($byPath[$fsp] ?? null) : null;
                if (! $port) {
                    $result->note("{$cmd}: could not map to a PON port (slot ctx: ".($currentSlot ?? 'none').')');

                    continue;
                }
                $rows = self::parseOptical($text);
                if (empty($rows)) {
                    $result->note("{$cmd}: no optical rows parsed".(self::looksLikeError($text) ? ' — OLT said: '.self::firstErrorLine($text) : ''));
                }
                foreach ($rows as $ontId => $vals) {
                    $result->merge($port->id, $ontId, $vals);
                }

                continue;
            }

            if (stripos($cmd, 'mac-address') !== false) {
                $rows = self::parseMacTable($text);
                if (empty($rows)) {
                    $result->note("{$cmd}: no MAC rows parsed".(self::looksLikeError($text) ? ' — OLT said: '.self::firstErrorLine($text) : ''));
                }
                $unmapped = 0;
                foreach ($rows as $row) {
                    $port = $byPath[$row['fsp']] ?? null;
                    if (! $port || $row['ont_id'] === null) {
                        $unmapped++;

                        continue;
                    }
                    $result->merge($port->id, $row['ont_id'], ['macs' => [$row['mac']]]);
                }
                if ($unmapped) {
                    $result->note("{$cmd}: {$unmapped} MAC row(s) did not match a known PON port / ONT id");
                }
            }
        }

        $c = $result->counts();
        $result->note("Parsed optical for {$c['optical']} ONU(s), MACs for {$c['mac']} ONU(s).");

        return $result;
    }

    /**
     * Parse "display ont optical-info … all" output.
     *
     * @return array<int, array{rx_power: ?float, tx_power: ?float, olt_rx_power: ?float}>
     */
    public static function parseOptical(string $text): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $text) ?: [];
        $rows = [];

        // Locate a header line to learn column order.
        $order = null; // index → key
        foreach ($lines as $line) {
            if (preg_match('/ONT[-\s]?ID/i', $line) && preg_match('/Rx\s*power/i', $line)) {
                $order = self::opticalColumnOrder($line);
                break;
            }
        }
        // Default order when no header matched: id, rx, tx, olt-rx.
        $order ??= [0 => 'id', 1 => 'rx_power', 2 => 'tx_power', 3 => 'olt_rx_power'];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || ! preg_match('/^\d+\s+-?[\d\.\-]/', $line)) {
                continue;
            }
            // Column separators are runs of 2+ spaces, but values may be single-spaced; split on whitespace.
            $tokens = preg_split('/\s+/', $line) ?: [];
            if (count($tokens) < 2) {
                continue;
            }
            $vals = ['rx_power' => null, 'tx_power' => null, 'olt_rx_power' => null];
            $id = null;
            foreach ($order as $i => $key) {
                if (! isset($tokens[$i])) {
                    continue;
                }
                if ($key === 'id') {
                    $id = (int) $tokens[$i];
                } elseif (array_key_exists($key, $vals)) {
                    $vals[$key] = self::dbm($tokens[$i]);
                }
            }
            if ($id === null) {
                continue;
            }
            if ($vals['rx_power'] === null && $vals['tx_power'] === null && $vals['olt_rx_power'] === null) {
                continue;
            }
            $rows[$id] = $vals;
        }

        // Block format ("ONT-ID : 1 / Rx optical power(dBm) : -18.55 …"), used
        // by the single-ONT variant and some newer firmware.
        if (empty($rows)) {
            $blocks = preg_split('/ONT[-\s]?ID\s*[:：]\s*(\d+)/i', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
            for ($i = 1; $i + 1 < count($blocks); $i += 2) {
                $id = (int) $blocks[$i];
                $body = $blocks[$i + 1];
                $rx = preg_match('/Rx\s*(?:optical\s*)?power[^:\n]*[:：]\s*(-?\d+\.?\d*)/i', $body, $m) ? self::dbm($m[1]) : null;
                $tx = preg_match('/Tx\s*(?:optical\s*)?power[^:\n]*[:：]\s*(-?\d+\.?\d*)/i', $body, $m) ? self::dbm($m[1]) : null;
                $olt = preg_match('/OLT\s*Rx\s*ONT[^:\n]*[:：]\s*(-?\d+\.?\d*)/i', $body, $m) ? self::dbm($m[1]) : null;
                if ($rx !== null || $tx !== null || $olt !== null) {
                    $rows[$id] = ['rx_power' => $rx, 'tx_power' => $tx, 'olt_rx_power' => $olt];
                }
            }
        }

        return $rows;
    }

    /** @return array<int, string> token index → id|rx_power|tx_power|olt_rx_power|other */
    private static function opticalColumnOrder(string $header): array
    {
        // Columns are separated by two or more spaces.
        $cols = preg_split('/\s{2,}/', trim($header)) ?: [];
        $order = [];
        foreach ($cols as $i => $col) {
            $order[$i] = match (true) {
                (bool) preg_match('/ONT[-\s]?ID/i', $col) => 'id',
                (bool) preg_match('/OLT\s*Rx/i', $col) => 'olt_rx_power',
                (bool) preg_match('/Rx\s*power/i', $col) => 'rx_power',
                (bool) preg_match('/Tx\s*power/i', $col) => 'tx_power',
                default => 'other',
            };
        }

        return $order;
    }

    /**
     * Parse "display mac-address …" output.
     *
     * @return array<int, array{mac: string, fsp: string, ont_id: ?int, type: ?string, vlan: ?int}>
     */
    public static function parseMacTable(string $text): array
    {
        $rows = [];
        $lines = preg_split('/\r\n|\r|\n/', $text) ?: [];

        // Learn whether the "VPI" (ONT ID) column exists and where F/S/P sits.
        foreach ($lines as $line) {
            if (! preg_match('/([0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}|(?:[0-9A-Fa-f]{2}[:\-]){5}[0-9A-Fa-f]{2})/', $line, $macM)) {
                continue;
            }
            $mac = self::mac($macM[1]);
            if (! $mac) {
                continue;
            }
            // "0 /1 /0" or "0/1/0" after the MAC
            $after = substr($line, strpos($line, $macM[1]) + strlen($macM[1]));
            if (! preg_match('/(\d+)\s*\/\s*(\d+)\s*\/\s*(\d+)\s+(\S+)(?:\s+(\S+))?(?:\s+(\S+))?/', $after, $m)) {
                continue;
            }
            $fsp = "{$m[1]}/{$m[2]}/{$m[3]}";
            // On GPON the VPI column carries the ONT ID; "-" when not applicable.
            $ontId = isset($m[4]) && is_numeric($m[4]) ? (int) $m[4] : null;
            $vlan = isset($m[6]) && is_numeric($m[6]) ? (int) $m[6] : (isset($m[5]) && is_numeric($m[5]) && $ontId === null ? (int) $m[5] : null);
            $type = preg_match('/\b(dynamic|static|sticky)\b/i', $after, $t) ? strtolower($t[1]) : null;

            $rows[] = ['mac' => $mac, 'fsp' => $fsp, 'ont_id' => $ontId, 'type' => $type, 'vlan' => $vlan];
        }

        return $rows;
    }

    private static function looksLikeError(string $text): bool
    {
        return (bool) preg_match('/%\s*(Unknown command|Incomplete command|Wrong parameter|Failure|Error)|Parameter error|Unknown command/i', $text);
    }

    private static function firstErrorLine(string $text): string
    {
        foreach (preg_split('/\r\n|\r|\n/', $text) ?: [] as $line) {
            if (preg_match('/%|error|unknown|failure/i', $line)) {
                return trim($line);
            }
        }

        return '';
    }
}
