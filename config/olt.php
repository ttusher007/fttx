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
    | PON-TYPE OVERRIDES: GPON and EPON expose ONUs through different SNMP
    | tables, so each vendor may define a `pon_types.gpon` / `pon_types.epon`
    | block. Keys present there (oids, power_divisor, distance_multiplier, …)
    | replace the vendor defaults for an OLT whose `pon_type` matches. The OLT's
    | pon_type is chosen in the UI or auto-detected on "Test connection".
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
                // Optical DDM — hwGponOntOpticalDdmTable (.51.1.*). CONFIRMED on
                // MA5800-X2 V100R022 (2758 rows). Columns are indexed [port.onu]:
                //   .51.1.1 temperature  .51.1.3 ONT Tx power  .51.1.4 ONT Rx power
                //   .51.1.5 voltage      .51.1.6 bias current
                // Tx/Rx are dBm × 100, signed; 2147483647 = offline sentinel (→ null).
                // NOTE: this table is empty on older MA5683T V800R018 (power stays
                // null there until the firmware is upgraded).
                'tx_power'    => '1.3.6.1.4.1.2011.6.128.1.1.2.51.1.3',
                'rx_power'    => '1.3.6.1.4.1.2011.6.128.1.1.2.51.1.4',
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

            // Default map = GPON (GP3600). Used when pon_type is null/gpon.
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
            ],

            'pon_types' => [
                'gpon' => [
                    // Same as the defaults above — kept explicit for clarity.
                    'power_divisor' => 10,
                    'distance_multiplier' => 20,
                    'uptime_unit' => 'minutes',
                    'oids' => [
                        'run_status'   => '1.3.6.1.4.1.3320.10.3.3.1.4',
                        'serial'       => '1.3.6.1.4.1.3320.10.3.3.1.2',
                        'description'  => '1.3.6.1.2.1.31.1.1.1.18',
                        'rx_power'     => '1.3.6.1.4.1.3320.10.3.4.1.2',
                        'tx_power'     => '1.3.6.1.4.1.3320.10.3.4.1.3',
                        'distance'     => '1.3.6.1.4.1.3320.10.3.4.1.4',
                        'online_since' => '1.3.6.1.4.1.3320.10.3.4.1.5',
                    ],
                ],
                // BDCOM EPON tree (P3310/P3608/P33xx — bdcomEponOnu, enterprise 3320.101).
                // Columns documented in NMS-EPON-* MIBs; VERIFY with olt:snmp-debug
                // against your EPON OLT and adjust the scaling below if needed.
                'epon' => [
                    'power_divisor' => 10,   // raw dBm × 10 on most EPON firmwares
                    'distance_multiplier' => 1,
                    'uptime_unit' => 'seconds',
                    'oids' => [
                        'run_status'   => '1.3.6.1.4.1.3320.101.10.1.1.26', // bdcomEponOnuStatus
                        'serial'       => '1.3.6.1.4.1.3320.101.10.1.1.3',  // bdcomEponOnuMacAddr (EPON identifies by MAC)
                        'mac'          => '1.3.6.1.4.1.3320.101.10.1.1.3',
                        'description'  => '1.3.6.1.2.1.31.1.1.1.18',
                        'rx_power'     => '1.3.6.1.4.1.3320.101.10.5.1.5',
                        'tx_power'     => '1.3.6.1.4.1.3320.101.10.5.1.6',
                        'distance'     => '1.3.6.1.4.1.3320.101.10.1.1.20',
                    ],
                ],
            ],
        ],

        'vsol' => [
            'driver' => VsolDriver::class,
            // VSOL optical power columns are OCTET STRINGs already expressed in
            // dBm (e.g. "-21.35"), so no scaling is applied (divisor = 1).
            'power_divisor' => 1,
            'distance_unit' => 'm',

            // ONU online/offline always comes from IF-MIB (ifDescr "GPONxxONUyy"
            // / ifOperStatus) in VsolDriver; the OID map ENRICHES that spine
            // (serial, power, description, …), joined by the "pon.onu" index.
            //
            // DEFAULT = the V1600G GPON tree (.6.1.1.*), CONFIRMED on AFTABNAGAR
            // V2.1.16: gOnuDetailInfoSn (.6.1.1.4.1.5) returned 559 serials. This
            // is the default so a VSOL OLT with no pon_type set still gets data
            // (most VSOL units here are GPON). The older V1600D tree (.5.12.*)
            // returned 0 rows on this firmware and is NOT used.
            'oids' => [
                'serial'      => '1.3.6.1.4.1.37950.1.1.6.1.1.4.1.5',  // gOnuDetailInfoSn (CONFIRMED)
                'description' => '1.3.6.1.4.1.37950.1.1.6.1.1.4.1.24', // gOnuDetailInfoOnuDesc
                // Optical (gOnuOpticalInfoTable .6.1.1.3): col 6 txPwr, col 7 rxPwr.
                // Returned 0 rows on V2.1.16 (ONU optical may not be exposed on this
                // firmware) — wired so it populates automatically when available.
                'tx_power'    => '1.3.6.1.4.1.37950.1.1.6.1.1.3.1.6',
                'rx_power'    => '1.3.6.1.4.1.37950.1.1.6.1.1.3.1.7',
            ],

            'pon_types' => [
                // VSOL V1600G GPON — confirmed tree (see default oids above).
                'gpon' => [
                    'power_divisor' => 1,
                    'oids' => [
                        'serial'      => '1.3.6.1.4.1.37950.1.1.6.1.1.4.1.5',
                        'description' => '1.3.6.1.4.1.37950.1.1.6.1.1.4.1.24',
                        'tx_power'    => '1.3.6.1.4.1.37950.1.1.6.1.1.3.1.6',
                        'rx_power'    => '1.3.6.1.4.1.37950.1.1.6.1.1.3.1.7',
                    ],
                ],
                // VSOL EPON (V1600D tree). EPON identifies ONUs by MAC via
                // onuListTable (.5.12.1.9); optical from onuRecievePowerTable.
                // UNCONFIRMED — verify with olt:snmp-debug on an EPON unit.
                'epon' => [
                    'power_divisor' => 1,
                    'oids' => [
                        'serial'   => '1.3.6.1.4.1.37950.1.1.5.12.1.9.1.5', // onuListTable.macAddress
                        'mac'      => '1.3.6.1.4.1.37950.1.1.5.12.1.9.1.5',
                        'rx_power' => '1.3.6.1.4.1.37950.1.1.5.12.1.28.1.3', // onuRecievePowerTable
                    ],
                ],
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
