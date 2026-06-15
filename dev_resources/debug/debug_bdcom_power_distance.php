<?php
/**
 * BDCom GP3600 — raw optical power & distance debug dump.
 *
 * Run:
 *   php dev_resources/debug_bdcom_power_distance.php <OLT_IP> <COMMUNITY> [port]
 *
 * Example:
 *   php dev_resources/debug_bdcom_power_distance.php 192.168.1.1 public
 *
 * Output is printed to stdout AND saved to dev_resources/bdcom_power_distance_dump.txt
 */

$ip        = $argv[1] ?? null;
$community = $argv[2] ?? 'public';
$port      = (int) ($argv[3] ?? 161);

if (! $ip) {
    fwrite(STDERR, "Usage: php {$argv[0]} <OLT_IP> [community] [port]\n");
    exit(1);
}

// ---- Session (mirrors SnmpClient exactly) ----
$session = new SNMP(SNMP::VERSION_2c, "{$ip}:{$port}", $community, 5_000_000, 1);
$session->oid_output_format = SNMP_OID_OUTPUT_NUMERIC;
$session->valueretrieval    = SNMP_VALUE_PLAIN;
$session->enum_print        = false;
$session->exceptions_enabled = SNMP::ERRNO_ANY;

// ---- Helper: walk matching SnmpClient::walk() ----
function debugWalk(SNMP $session, string $baseOid, int $maxRep = 20): array
{
    $base = ltrim($baseOid, '.');
    try {
        $raw = $session->walk($baseOid, false, $maxRep);
    } catch (Throwable $e) {
        return ['__error__' => $e->getMessage()];
    }

    if ($raw === false) {
        return ['__error__' => 'walk() returned false (errno=' . $session->getErrno() . ' ' . $session->getError() . ')'];
    }

    $out = [];
    foreach ($raw as $oid => $value) {
        $oid   = ltrim($oid, '.');
        $index = str_starts_with($oid, $base . '.') ? substr($oid, strlen($base) + 1) : $oid;
        $out[$index] = $value;
    }
    return $out;
}

// ---- Helper: single GET ----
function debugGet(SNMP $session, string $oid): string
{
    try {
        $v = $session->get($oid);
        return $v === false ? 'GET returned false (errno=' . $session->getErrno() . ' ' . $session->getError() . ')' : $v;
    } catch (Throwable $e) {
        return 'EXCEPTION: ' . $e->getMessage();
    }
}

$out = [];

$out[] = str_repeat('=', 70);
$out[] = "BDCom GP3600 Power & Distance Debug";
$out[] = "OLT:       {$ip}:{$port}";
$out[] = "Community: {$community}";
$out[] = "Time:      " . date('Y-m-d H:i:s');
$out[] = 'PHP:       ' . phpversion() . '  ext-snmp: ' . phpversion('snmp');
$out[] = str_repeat('=', 70);

// ---- Step 1: Basic connectivity ----
$out[] = '';
$out[] = '>>> STEP 1: Basic connectivity (sysDescr GET)';
$sysDescr = debugGet($session, '1.3.6.1.2.1.1.1.0');
$out[] = "  sysDescr = {$sysDescr}";
$sysName = debugGet($session, '1.3.6.1.2.1.1.5.0');
$out[] = "  sysName  = {$sysName}";

// ---- Step 2: ifDescr walk (standard IF-MIB) ----
$out[] = '';
$out[] = '>>> STEP 2: ifDescr walk (1.3.6.1.2.1.2.2.1.2) — should list all interfaces';
$ifDescr = debugWalk($session, '1.3.6.1.2.1.2.2.1.2');
if (isset($ifDescr['__error__'])) {
    $out[] = '  ERROR: ' . $ifDescr['__error__'];
} else {
    foreach ($ifDescr as $idx => $name) {
        $out[] = "  [{$idx}]  {$name}";
    }
    $out[] = '  Total: ' . count($ifDescr);
}

// ---- Step 3: GPON run-status walk ----
$out[] = '';
$out[] = '>>> STEP 3: GPON ONU run-status walk (1.3.6.1.4.1.3320.10.3.3.1.4)';
$runStatus = debugWalk($session, '1.3.6.1.4.1.3320.10.3.3.1.4');
if (isset($runStatus['__error__'])) {
    $out[] = '  ERROR: ' . $runStatus['__error__'];
} else {
    foreach ($runStatus as $idx => $val) {
        $out[] = "  [{$idx}]  raw={$val}  (3=online 0=offline 1=inactive 2=disabled)";
    }
    $out[] = '  Total: ' . count($runStatus);
}

// ---- Step 4: Walk ALL columns of the optical table ----
$opticalBase = '1.3.6.1.4.1.3320.10.3.4';
$out[] = '';
$out[] = ">>> STEP 4: Full optical table walk ({$opticalBase}) — every column";
$allOptical = debugWalk($session, $opticalBase, 50);
if (isset($allOptical['__error__'])) {
    $out[] = '  ERROR: ' . $allOptical['__error__'];
} else {
    foreach ($allOptical as $idx => $val) {
        // idx = "1.N" where first digit is column number
        $out[] = "  [{$idx}]  {$val}";
    }
    $out[] = '  Total: ' . count($allOptical);
}

// ---- Step 5: Individual column walks ----
$columns = [
    '1' => 'col1 — ifIndex / row identifier',
    '2' => 'col2 — currently rx_power in config',
    '3' => 'col3 — currently tx_power in config',
    '4' => 'col4 — currently distance in config',
    '5' => 'col5 — currently online_since (uptime minutes) in config',
    '6' => 'col6 — unknown',
    '7' => 'col7 — unknown',
    '8' => 'col8 — unknown',
];

$out[] = '';
$out[] = '>>> STEP 5: Individual optical column walks';

$colData = [];
foreach ($columns as $col => $label) {
    $oid  = "1.3.6.1.4.1.3320.10.3.4.1.{$col}";
    $rows = debugWalk($session, $oid);
    $colData[$col] = $rows;

    $out[] = '';
    $out[] = "  -- {$oid} ({$label})";
    if (isset($rows['__error__'])) {
        $out[] = "     ERROR: {$rows['__error__']}";
    } elseif (empty($rows)) {
        $out[] = '     (empty)';
    } else {
        foreach (array_slice($rows, 0, 20, true) as $idx => $val) {
            $extra = '';
            if (is_numeric($val)) {
                $d10  = round($val / 10,  2);
                $d100 = round($val / 100, 2);
                if (in_array($col, ['2', '3'])) {
                    $extra = "   → ÷10={$d10} dBm  ÷100={$d100} dBm";
                } elseif ($col === '4') {
                    $extra = "   → as-m={$val}m  ÷10=" . round($val/10,1) . "m  ÷100=" . round($val/100,1) . "m  ×10=" . ($val*10) . "m";
                }
            }
            $out[] = "     [{$idx}]  {$val}{$extra}";
        }
        if (count($rows) > 20) {
            $out[] = '     ... (' . count($rows) . ' total, showing first 20)';
        }
    }
}

// ---- Step 6: Side-by-side for first 15 ONUs ----
$out[] = '';
$out[] = str_repeat('=', 70);
$out[] = 'STEP 6: Side-by-side (first 15 ONUs) — raw values';
$out[] = str_repeat('=', 70);

// Collect all indexes from col2/col3/col4
$indexes = array_unique(array_merge(
    array_keys(array_filter($colData['2'] ?? [], fn($v) => $v !== '__error__')),
    array_keys(array_filter($colData['3'] ?? [], fn($v) => $v !== '__error__')),
    array_keys(array_filter($colData['4'] ?? [], fn($v) => $v !== '__error__')),
));
sort($indexes);

if (empty($indexes)) {
    $out[] = '  No ONU indexes found in col2/col3/col4.';
} else {
    $out[] = sprintf("%-20s %10s %10s %10s %10s %10s", 'index', 'col2', 'col3', 'col4', 'col5', 'col1');
    $out[] = str_repeat('-', 72);

    foreach (array_slice($indexes, 0, 15) as $idx) {
        $c1 = ($colData['1'][$idx] ?? '—');
        $c2 = ($colData['2'][$idx] ?? '—');
        $c3 = ($colData['3'][$idx] ?? '—');
        $c4 = ($colData['4'][$idx] ?? '—');
        $c5 = ($colData['5'][$idx] ?? '—');
        $out[] = sprintf("%-20s %10s %10s %10s %10s %10s", $idx, $c2, $c3, $c4, $c5, $c1);
    }

    $out[] = '';
    $out[] = 'Power col2 & col3 interpretations:';
    $out[] = sprintf("%-20s %14s %14s %14s %14s", 'index', 'col2÷10 dBm', 'col3÷10 dBm', 'col2÷100 dBm', 'col3÷100 dBm');
    $out[] = str_repeat('-', 78);
    foreach (array_slice($indexes, 0, 15) as $idx) {
        $c2 = $colData['2'][$idx] ?? null;
        $c3 = $colData['3'][$idx] ?? null;
        $out[] = sprintf("%-20s %14s %14s %14s %14s",
            $idx,
            is_numeric($c2) ? round($c2 / 10,  2) : '—',
            is_numeric($c3) ? round($c3 / 10,  2) : '—',
            is_numeric($c2) ? round($c2 / 100, 2) : '—',
            is_numeric($c3) ? round($c3 / 100, 2) : '—',
        );
    }

    $out[] = '';
    $out[] = 'Distance col4 interpretations:';
    $out[] = sprintf("%-20s %10s %12s %12s %12s", 'index', 'raw', 'raw=m', 'raw÷100=m', 'raw÷10=m');
    $out[] = str_repeat('-', 68);
    foreach (array_slice($indexes, 0, 15) as $idx) {
        $c4 = $colData['4'][$idx] ?? null;
        $out[] = sprintf("%-20s %10s %12s %12s %12s",
            $idx,
            $c4 ?? '—',
            is_numeric($c4) ? $c4 . ' m' : '—',
            is_numeric($c4) ? round($c4 / 100, 1) . ' m' : '—',
            is_numeric($c4) ? round($c4 / 10, 1) . ' m' : '—',
        );
    }
}

$out[] = '';
$out[] = str_repeat('=', 70);
$out[] = 'END OF DUMP';
$out[] = str_repeat('=', 70);

$session->close();

$text    = implode("\n", $out) . "\n";
$outFile = __DIR__ . '/debug/bdcom_power_distance_dump.txt';
@mkdir(dirname($outFile), 0777, true);
file_put_contents($outFile, $text);

echo $text;
echo "\n[Saved to: {$outFile}]\n";
