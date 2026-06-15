<?php

use App\Services\Olt\Drivers\BdcomDriver;
use App\Services\Olt\Drivers\GenericDriver;
use App\Services\Olt\Drivers\HuaweiDriver;
use App\Services\Olt\Drivers\VsolDriver;

return [

    /*
    |--------------------------------------------------------------------------
    | Simulation mode
    |--------------------------------------------------------------------------
    |
    | When enabled (globally, or per-OLT via the `is_simulated` column), the
    | sync engine generates realistic fake ONU data instead of hitting a real
    | device over SNMP. This lets you exercise the entire app (UI, API, jobs,
    | dashboard) without physical hardware. Turn it OFF in production.
    |
    */
    'simulate' => env('OLT_SIMULATE', false),

    /*
    |--------------------------------------------------------------------------
    | SNMP transport defaults
    |--------------------------------------------------------------------------
    */
    'snmp' => [
        'timeout' => (int) env('OLT_SNMP_TIMEOUT', 3_000_000), // microseconds
        'retries' => (int) env('OLT_SNMP_RETRIES', 2),
        'max_repetitions' => (int) env('OLT_SNMP_MAX_REPETITIONS', 20), // GETBULK window
    ],

    /*
    |--------------------------------------------------------------------------
    | Sync scheduler
    |--------------------------------------------------------------------------
    |
    | `default_interval` is how often (minutes) an OLT is re-synced when it has
    | no per-OLT override. `concurrency` caps how many OLT sync jobs may run at
    | once so 200+ devices don't stampede the queue worker / network.
    |
    */
    'sync' => [
        'default_interval' => (int) env('OLT_SYNC_INTERVAL', 15),
        'concurrency' => (int) env('OLT_SYNC_CONCURRENCY', 10),
        'queue' => env('OLT_SYNC_QUEUE', 'olt-sync'),
        'lock_seconds' => 600, // WithoutOverlapping release window
    ],

    /*
    |--------------------------------------------------------------------------
    | Standard OIDs (RFC1213 / SNMPv2-MIB / IF-MIB) — vendor independent
    |--------------------------------------------------------------------------
    */
    'standard' => [
        'sysDescr' => '1.3.6.1.2.1.1.1.0',
        'sysObjectID' => '1.3.6.1.2.1.1.2.0',
        'sysUpTime' => '1.3.6.1.2.1.1.3.0',
        'sysName' => '1.3.6.1.2.1.1.5.0',
        'sysLocation' => '1.3.6.1.2.1.1.6.0',
        'ifNumber' => '1.3.6.1.2.1.2.1.0',
        'ifDescr' => '1.3.6.1.2.1.2.2.1.2',
        'ifOperStatus' => '1.3.6.1.2.1.2.2.1.8',
        'ifAdminStatus' => '1.3.6.1.2.1.2.2.1.7',
    ],

    /*
    |--------------------------------------------------------------------------
    | Vendor ONU/PON OID maps
    |--------------------------------------------------------------------------
    |
    | IMPORTANT: GPON/EPON ONU OIDs vary by vendor AND firmware version. The
    | values below are the commonly documented trees per vendor and are a
    | sensible starting point, but you MUST verify them against your specific
    | devices with `snmpwalk`. Each driver reads its map from here, so you can
    | tune OIDs per vendor/version without touching code.
    |
    | `power_divisor` converts the raw integer the OLT returns into dBm.
    |
    */
    'vendors' => [

        'huawei' => [
            'driver' => HuaweiDriver::class,
            'power_divisor' => 100,    // raw is dBm × 100, signed
            'distance_unit' => 'm',    // distance is in metres; -1 means offline/unknown
            'oids' => [
                // hwGponDeviceOntInfoTable (MA5600/MA5683T/MA5800)
                'onu_index'   => '1.3.6.1.4.1.2011.6.128.1.1.2.43.1.2',
                'serial'      => '1.3.6.1.4.1.2011.6.128.1.1.2.43.1.3',
                'description' => '1.3.6.1.4.1.2011.6.128.1.1.2.43.1.9',
                // hwGponDeviceOntControlTable
                'run_status'  => '1.3.6.1.4.1.2011.6.128.1.1.2.46.1.15',
                'distance'    => '1.3.6.1.4.1.2011.6.128.1.1.2.46.1.20',
                // .46.1.23 returns SNMP DateAndTime OctetString (hex) — decoded in HuaweiDriver
                'online_since' => '1.3.6.1.4.1.2011.6.128.1.1.2.46.1.23',
                // Optical DDM (rx/tx power) is NOT available via SNMP on MA5683T V800R018.
                // hwGponOntOpticalDdmTable (.51.1.*) exists only from V800R019+.
                // Power will show null until firmware is upgraded or a later model is used.
            ],
        ],

        'bdcom' => [
            'driver' => BdcomDriver::class,
            'power_divisor' => 10,
            'distance_unit' => 'm',
            // GP3600-08 col.4 returns distance in 20-metre units (confirmed via snmpwalk:
            // raw 33 = 660 m, raw 32 = 640 m on a site where actual fibre runs are ~630–650 m).
            'distance_multiplier' => 20,
            // GP3600-08 optical col.5 returns ONU uptime in minutes (not seconds).
            'uptime_unit' => 'minutes',
            'oids' => [
                // GP3600 GPON (confirmed against GP3600-08 via snmpwalk).
                'run_status'   => '1.3.6.1.4.1.3320.10.3.3.1.4',
                'serial'       => '1.3.6.1.4.1.3320.10.3.3.1.2',  // GPON serial — format "HWTC:XXXXXXXX"
                'description'  => '1.3.6.1.2.1.31.1.1.1.18',
                'rx_power'     => '1.3.6.1.4.1.3320.10.3.4.1.2',
                'tx_power'     => '1.3.6.1.4.1.3320.10.3.4.1.3',
                'distance'     => '1.3.6.1.4.1.3320.10.3.4.1.4',
                'online_since' => '1.3.6.1.4.1.3320.10.3.4.1.5',  // ONU uptime in minutes (optical table col.5)
                // No Ethernet/CPE MAC exposed via SNMP on GP3600-08.
                // Legacy EPON tree (P3310/P3608 etc.):
                // 'run_status' => '1.3.6.1.4.1.3320.101.10.1.1.26',
                // 'serial' => '1.3.6.1.4.1.3320.101.10.1.1.3',
                // 'rx_power' => '1.3.6.1.4.1.3320.101.10.5.1.5',
                // 'tx_power' => '1.3.6.1.4.1.3320.101.10.5.1.6',
            ],
        ],

        'vsol' => [
            'driver' => VsolDriver::class,
            'power_divisor' => 100,
            'distance_unit' => 'm',
            'oids' => [
                // ONU enumeration for VSOL is done via IF-MIB ifDescr/ifOperStatus
                // in VsolDriver::fetchOnus() — the vendor tree (.37950.1.1.5.10.1.1)
                // is a port-management table, not a per-ONU GPON table.
                //
                // The optical table (.37950.1.1.5.12.1.1) returned 0 rows on V2.1.16
                // firmware. Serial numbers and optical power (rx/tx) are not yet
                // available via SNMP. Use `php artisan olt:snmp-debug {id}` to
                // re-probe after a firmware upgrade and add OIDs here when found.
            ],
        ],

        // Fallback for any vendor not explicitly mapped. Uses standard OIDs
        // only (ports/system); ONU enumeration requires a real vendor map.
        'generic' => [
            'driver' => GenericDriver::class,
            'power_divisor' => 100,
            'distance_unit' => 'm',
            'oids' => [],
        ],
    ],
];
