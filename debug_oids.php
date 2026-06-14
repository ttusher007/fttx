<?php
require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Olt;

$olt = Olt::find(7);
$host = $olt->ip_address . ':' . $olt->snmp_port;
$community = (string) $olt->snmp_community;
snmp_set_oid_output_format(SNMP_OID_OUTPUT_NUMERIC);
snmp_set_valueretrieval(SNMP_VALUE_LIBRARY);

function stripType(string $v): string {
    // Remove "TYPE: " prefix and surrounding quotes
    $v = preg_replace('/^\w[\w-]*:\s*/', '', $v);
    return trim($v, '"');
}

// Enumerate ONUs from IF-MIB ifDescr
echo "=== Enumerating ONU virtual interfaces from IF-MIB ===\n";
$ifDescrResult = @snmp2_real_walk($host, $community, "1.3.6.1.2.1.2.2.1.2", 3000000, 20);
$onuIfs = [];
if ($ifDescrResult) {
    foreach ($ifDescrResult as $oid => $val) {
        $clean = stripType($val);
        if (preg_match('/^GPON\d+\/\d+:\d+$/', $clean)) {
            $ifIdx = preg_replace('/^.*\.2\.2\.1\.2\./', '', $oid);
            $onuIfs[$ifIdx] = $clean;
        }
    }
}
echo "Found " . count($onuIfs) . " ONU virtual interfaces\n";
$sampleOnuIdxs = array_keys(array_slice($onuIfs, 0, 5, true));
echo "Sample ifIndexes: " . implode(', ', $sampleOnuIdxs) . "\n\n";

foreach ($sampleOnuIdxs as $ifIdx) {
    echo "=== ONU ifIdx=$ifIdx ({$onuIfs[$ifIdx]}) ===\n";
    $oids = [
        "ifDescr"     => "1.3.6.1.2.1.2.2.1.2.$ifIdx",
        "ifOperStat"  => "1.3.6.1.2.1.2.2.1.8.$ifIdx",
        "ifAdminStat" => "1.3.6.1.2.1.2.2.1.7.$ifIdx",
        "ifPhysAddr"  => "1.3.6.1.2.1.2.2.1.6.$ifIdx",
        "bdDesc"      => "1.3.6.1.4.1.3320.9.64.4.1.1.2.$ifIdx",
        "bdCol3"      => "1.3.6.1.4.1.3320.9.64.4.1.1.3.$ifIdx",
        "bdCol4"      => "1.3.6.1.4.1.3320.9.64.4.1.1.4.$ifIdx",
        "bd152_3_2"   => "1.3.6.1.4.1.3320.152.3.1.2.$ifIdx",
        "bd151_3_2"   => "1.3.6.1.4.1.3320.151.3.1.2.$ifIdx",
        "bd151_3_3"   => "1.3.6.1.4.1.3320.151.3.1.3.$ifIdx",
        "bd151_3_4"   => "1.3.6.1.4.1.3320.151.3.1.4.$ifIdx",
        "bd151_3_5"   => "1.3.6.1.4.1.3320.151.3.1.5.$ifIdx",
        "bd151_3_6"   => "1.3.6.1.4.1.3320.151.3.1.6.$ifIdx",
        "bd151_3_7"   => "1.3.6.1.4.1.3320.151.3.1.7.$ifIdx",
    ];
    // snmp2_get with array doesn't work in procedural - use one at a time in batch
    foreach (array_chunk(array_values($oids), 5) as $batch) {
        $keys = array_keys(array_slice($oids, 0, 5, true));
        $result = @snmp2_get($host, $community, $batch[0], 3000000, 1);
        // actually need associative - loop individually
    }
    // Fallback: individual gets
    foreach ($oids as $name => $oid) {
        $val = @snmp2_get($host, $community, $oid, 3000000, 1);
        if ($val && !str_contains($val, 'No Such') && $val !== false) {
            $clean = stripType($val);
            if ($clean !== '' && $clean !== '0' && $clean !== '""') {
                echo "  $name: $clean\n";
            }
        }
    }
    echo "\n";
}

// Re-check .9.63.1.5.2.1 with explicit walk from OID 0
echo "=== Re-walking .9.63.1.5.2.1.2 explicitly ===\n";
$result = @snmp2_real_walk($host, $community, "1.3.6.1.4.1.3320.9.63.1.5.2.1.2", 3000000, 20);
echo "Rows found: " . count($result ?? []) . "\n";
foreach (($result ?? []) as $oid => $val) {
    $short = preg_replace('/^.*\.5\.2\.1\.2\./', '', $oid);
    echo "  [$short] = $val\n";
}

// Try explicitly GET .9.63.1.5.2.1.2.1020.41.41.46.1.48.10 (from previous session)
echo "\n=== GET previous-session compound OID ===\n";
$result = @snmp2_get($host, $community, "1.3.6.1.4.1.3320.9.63.1.5.2.1.2.1020.41.41.46.1.48.10", 3000000, 1);
echo "  .5.2.1.2.1020.41.41.46.1.48.10 = " . ($result ?: 'no data') . "\n";

echo "\nDone.\n";
