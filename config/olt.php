<?php

use App\Services\Olt\Drivers\BdcomDriver;
use App\Services\Olt\Drivers\CDataDriver;
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
    | CLI (SSH / Telnet) enrichment
    |--------------------------------------------------------------------------
    |
    | Some firmwares do not expose everything over SNMP (e.g. Huawei MA5683T
    | V800R018 has no per-ONT optical table; customer MACs are not in any
    | Huawei MIB). For OLTs with `cli_enabled`, the app logs into the OLT
    | through the Python collector (dev_resources/python), runs the vendor's
    | CLI commands and merges the parsed values into the ONU rows. This runs
    | as its own queued job on its own interval because CLI is much slower
    | than SNMP. Command templates are here so they can be tuned per site
    | without code changes; `{fsp}` is replaced by frame/slot/port (0/1/0).
    |
    */
    'cli' => [
        'default_interval' => (int) env('OLT_CLI_INTERVAL', 60), // minutes
        'queue' => env('OLT_SYNC_QUEUE', 'olt-sync'),
        'job_timeout' => (int) env('OLT_CLI_JOB_TIMEOUT', 1500), // seconds
        'lock_seconds' => 1800,

        'vendors' => [
            'huawei' => [
                'profile' => \App\Services\Olt\Cli\Profiles\HuaweiCliProfile::class,
                // Commands run once after login, before any data command.
                // "undo interactive" stops the "{ <cr>|... }" parameter menus,
                // "undo smart" stops auto-completion, "scroll" disables paging.
                'prep' => ['enable', 'undo smart', 'undo interactive', 'undo alarm output all', 'scroll 512', 'config'],
                // Per-PON-port commands. Run from config mode (we enter
                // "interface gpon F/S" then use the port number only).
                'optical' => 'display ont optical-info {port} all',
                'mac' => 'display mac-address port {fsp}',
                'mac_scope' => 'enable', // run the MAC command from enable mode (not inside interface view)
                // Fallback when the per-port MAC command is unknown on a firmware.
                'mac_all' => 'display mac-address all',
                'use_mac_all' => false,
            ],
            'bdcom' => [
                'profile' => \App\Services\Olt\Cli\Profiles\BdcomCliProfile::class,
                'prep' => ['enable', 'terminal length 0'],
                'optical' => 'show gpon interface {ifname} onu optical-transceiver-diagnosis',
                'mac' => 'show mac address-table interface {ifname}',
            ],
            'vsol' => [
                'profile' => \App\Services\Olt\Cli\Profiles\VsolCliProfile::class,
                'prep' => ['enable', 'terminal length 0'],
                'optical' => 'show onu optical-info gpon {port} all',
                'mac' => 'show mac address-table gpon {port}',
            ],
        ],
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
        'ifName' => '1.3.6.1.2.1.31.1.1.1.1',
        'ifAlias' => '1.3.6.1.2.1.31.1.1.1.18',
    ],

    /*
    |--------------------------------------------------------------------------
    | Vendor ONU/PON OID maps
    |--------------------------------------------------------------------------
    |
    | Each vendor block drives AbstractVendorDriver::fetchOnus(). Recognised
    | `oids` keys (all optional):
    |
    |   serial, run_status, description, model, distance, online_since,
    |   last_down_time, last_down_cause, mac_count,
    |   rx_power      downstream power received at the ONU
    |   tx_power      upstream power transmitted by the ONU
    |   olt_rx_power  upstream power received by the OLT ("Rx with ONU")
    |   mac           customer/router MAC learned behind the ONU
    |   onu_mac       the ONU's own MAC
    |
    | `fallback_oids` are tried per key when the primary table returns 0 rows
    | (different firmware generations expose the same data in different
    | tables). `walk.<key>` tunes how that column is walked: `per_port` walks
    | one PON port at a time (OLTs compute optical tables on demand and time
    | out on huge GETBULKs), `max_repetitions` / `timeout` (µs) open a slower,
    | smaller-window SNMP session for that column.
    |
    | `mac_fdb` lists bridge-FDB strategies (bdcom_fdb | q_bridge | d_bridge)
    | used to find customer MACs for OLTs whose ONUs are IF-MIB interfaces.
    |
    | PON-TYPE OVERRIDES: `pon_types.gpon` / `pon_types.epon` keys replace the
    | vendor defaults for an OLT whose `pon_type` matches.
    |
    */
    'vendors' => [

        'huawei' => [
            'driver' => HuaweiDriver::class,
            'power_divisor' => 100,    // raw is dBm × 100, signed
            'distance_unit' => 'm',    // distance is in metres; -1 means offline/unknown
            'oids' => [
                // hwGponDeviceOntConfigInfoTable (.43) — indexed [portIfIndex.ontId]
                'serial' => '1.3.6.1.4.1.2011.6.128.1.1.2.43.1.3',
                'description' => '1.3.6.1.4.1.2011.6.128.1.1.2.43.1.9',
                // hwGponDeviceOntVersionInfoTable (.45)
                'model' => '1.3.6.1.4.1.2011.6.128.1.1.2.45.1.4',   // hwGponDeviceOntEquipmentId (e.g. "HG8546M")
                'onu_mac' => '1.3.6.1.4.1.2011.6.128.1.1.2.45.1.10', // hwGponDeviceOntMac (-1 when unknown)
                // hwGponDeviceOntControlInfoTable (.46)
                'run_status' => '1.3.6.1.4.1.2011.6.128.1.1.2.46.1.15',
                'distance' => '1.3.6.1.4.1.2011.6.128.1.1.2.46.1.20',  // hwGponDeviceOntControlRanging (m)
                'mac_count' => '1.3.6.1.4.1.2011.6.128.1.1.2.46.1.21', // hwGponDeviceOntControlMacCount
                // DateAndTime OctetStrings (hex) — decoded in HuaweiDriver.
                'online_since' => '1.3.6.1.4.1.2011.6.128.1.1.2.46.1.22',    // LastUpTime
                'last_down_time' => '1.3.6.1.4.1.2011.6.128.1.1.2.46.1.23',  // LastDownTime
                'last_down_cause' => '1.3.6.1.4.1.2011.6.128.1.1.2.46.1.24', // LastDownCause (enum)
                // hwGponDeviceOntOpticalDdmInfoTable (.51) — CONFIRMED on MA5800
                // V100R022. dBm × 100 signed; 2147483647 = no reading (→ null).
                //   .1 temperature  .2 bias  .3 ONT Tx  .4 ONT Rx  .5 voltage
                //   .6 OLT Rx of this ONT  .7 CATV Rx
                'tx_power' => '1.3.6.1.4.1.2011.6.128.1.1.2.51.1.3',
                'rx_power' => '1.3.6.1.4.1.2011.6.128.1.1.2.51.1.4',
                'olt_rx_power' => '1.3.6.1.4.1.2011.6.128.1.1.2.51.1.6',
            ],
            // Older MA5600T/MA5683T firmware (V800R0xx) may expose ONT optics in
            // HUAWEI-LINE-COMMON-MIB hwXponDeviceOntDdmInfoExTable instead of .51.
            // Indexed [portIfIndex.ontIndex] as well. Tried when .51 is empty.
            'fallback_oids' => [
                'tx_power' => '1.3.6.1.4.1.2011.6.158.1.1.1.2.1.16',
                'rx_power' => '1.3.6.1.4.1.2011.6.158.1.1.1.2.1.21',
                'olt_rx_power' => '1.3.6.1.4.1.2011.6.158.1.1.1.2.1.26',
            ],
            // Optical tables are computed live by the OLT (it polls each ONT),
            // so a single big GETBULK walk easily exceeds the 3 s default and
            // net-snmp gives up → "0 rows". Walk per PON port with a small
            // window and a generous timeout instead.
            'walk' => [
                'tx_power' => ['per_port' => true, 'max_repetitions' => 10, 'timeout' => 15_000_000, 'retries' => 1],
                'rx_power' => ['per_port' => true, 'max_repetitions' => 10, 'timeout' => 15_000_000, 'retries' => 1],
                'olt_rx_power' => ['per_port' => true, 'max_repetitions' => 10, 'timeout' => 15_000_000, 'retries' => 1],
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
            // Customer MACs: BDCOM exposes the bridge FDB per interface
            // (NMS-MAC-MIB fdbReadByPortTable), with standard bridge MIBs as fallback.
            'mac_fdb' => ['bdcom_fdb', 'q_bridge', 'd_bridge'],

            // Default map = GPON (GP3600). Used when pon_type is null/gpon.
            'oids' => [
                // gponOnuStatusTable (3320.10.3.3) / gponOnuOpticalPowerTable (3320.10.3.4)
                // — indexed by the ONU ifIndex. CONFIRMED on GP3600-08.
                'run_status' => '1.3.6.1.4.1.3320.10.3.3.1.4',
                'serial' => '1.3.6.1.4.1.3320.10.3.3.1.2',  // GPON serial — format "HWTC:XXXXXXXX"
                'description' => '1.3.6.1.2.1.31.1.1.1.18',
                'rx_power' => '1.3.6.1.4.1.3320.10.3.4.1.2',
                'tx_power' => '1.3.6.1.4.1.3320.10.3.4.1.3',
                'distance' => '1.3.6.1.4.1.3320.10.3.4.1.4',
                'online_since' => '1.3.6.1.4.1.3320.10.3.4.1.5',  // ONU uptime in minutes (optical table col.5)
                // gponOnuInfoTable (3320.10.3.1) — per NMS-GPON-MIB. Verify with Diagnostics.
                'onu_mac' => '1.3.6.1.4.1.3320.10.3.1.1.28',  // onuInfoOonuMacAddress
                'model' => '1.3.6.1.4.1.3320.10.3.1.1.3',     // onuVersion (vendor model string)
            ],

            'pon_types' => [
                'gpon' => [
                    'power_divisor' => 10,
                    'distance_multiplier' => 20,
                    'uptime_unit' => 'minutes',
                    'oids' => [
                        'run_status' => '1.3.6.1.4.1.3320.10.3.3.1.4',
                        'serial' => '1.3.6.1.4.1.3320.10.3.3.1.2',
                        'description' => '1.3.6.1.2.1.31.1.1.1.18',
                        'rx_power' => '1.3.6.1.4.1.3320.10.3.4.1.2',
                        'tx_power' => '1.3.6.1.4.1.3320.10.3.4.1.3',
                        'distance' => '1.3.6.1.4.1.3320.10.3.4.1.4',
                        'online_since' => '1.3.6.1.4.1.3320.10.3.4.1.5',
                        'onu_mac' => '1.3.6.1.4.1.3320.10.3.1.1.28',
                        'model' => '1.3.6.1.4.1.3320.10.3.1.1.3',
                    ],
                ],
                // BDCOM EPON (P3310/P3608) — NMS-EPON-ONU.MIB nmsepononuTable
                // 3320.101.10.1.1.<col>, indexed by the ONU (LLID) ifIndex:
                //   col 2 onuModuleID (model)  col 3 onuID (= ONU MAC, EPON identity)
                //   col 26 onuStatus: 0 authenticated 1 registered 2 deregistered
                //          3 auto_config 4 lost 5 standby
                //   col 27 onuDistance (m)
                // OLT-side Rx per ONU: NMS-EPON-OLT-PON-EXT ponOpticsRxPowerTable
                // 3320.101.108.1.3 (0.1 dBm), indexed by ONU ifIndex.
                // ONU-side rx/tx (10.5.1.5/6) are UNVERIFIED — check with Diagnostics.
                'epon' => [
                    'power_divisor' => 10,
                    'distance_multiplier' => 1,
                    'uptime_unit' => 'seconds',
                    'oids' => [
                        'run_status' => '1.3.6.1.4.1.3320.101.10.1.1.26',
                        'serial' => '1.3.6.1.4.1.3320.101.10.1.1.3',
                        'onu_mac' => '1.3.6.1.4.1.3320.101.10.1.1.3',
                        'model' => '1.3.6.1.4.1.3320.101.10.1.1.2',
                        'description' => '1.3.6.1.2.1.31.1.1.1.18',
                        'rx_power' => '1.3.6.1.4.1.3320.101.10.5.1.5',
                        'tx_power' => '1.3.6.1.4.1.3320.101.10.5.1.6',
                        'olt_rx_power' => '1.3.6.1.4.1.3320.101.108.1.3',
                        'distance' => '1.3.6.1.4.1.3320.101.10.1.1.27',
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
            // Customer MACs via standard bridge MIBs (ONUs are IF-MIB interfaces).
            'mac_fdb' => ['q_bridge', 'd_bridge'],

            // ONU online/offline always comes from IF-MIB (ifDescr "GPONxxONUyy"
            // / ifOperStatus) in VsolDriver; the OID map ENRICHES that spine
            // (serial, power, description, …), joined by the "pon.onu" index.
            //
            // DEFAULT = the V1600G GPON tree (.6.1.1.*), CONFIRMED on V2.1.16 and
            // V3.1.8. Optical rows exist on V3.x+ only (0 rows on V2.1.16).
            'oids' => [
                'serial' => '1.3.6.1.4.1.37950.1.1.6.1.1.4.1.5',   // gOnuDetailInfoSn (CONFIRMED)
                'description' => '1.3.6.1.4.1.37950.1.1.6.1.1.4.1.24', // gOnuDetailInfoOnuDesc
                'model' => '1.3.6.1.4.1.37950.1.1.6.1.1.4.1.17',   // gOnuDetailInfoModel ("N/A" → null)
                // gOnuOpticalInfoTable (.6.1.1.3): 6 txPwr, 7 rxPwr, 8 rxOptLevOlt (OLT Rx)
                'tx_power' => '1.3.6.1.4.1.37950.1.1.6.1.1.3.1.6',
                'rx_power' => '1.3.6.1.4.1.37950.1.1.6.1.1.3.1.7',
                'olt_rx_power' => '1.3.6.1.4.1.37950.1.1.6.1.1.3.1.8',
                // gOnuRttTable (.6.1.1.12) — distance per MIB; verify unit with Diagnostics.
                'distance' => '1.3.6.1.4.1.37950.1.1.6.1.1.12.1.3',
                // priOnuInfoTable (.6.1.8.1) — ONU's own MAC
                'onu_mac' => '1.3.6.1.4.1.37950.1.1.6.1.8.1.1.5',
            ],

            'pon_types' => [
                'gpon' => [
                    'power_divisor' => 1,
                    'oids' => [
                        'serial' => '1.3.6.1.4.1.37950.1.1.6.1.1.4.1.5',
                        'description' => '1.3.6.1.4.1.37950.1.1.6.1.1.4.1.24',
                        'model' => '1.3.6.1.4.1.37950.1.1.6.1.1.4.1.17',
                        'tx_power' => '1.3.6.1.4.1.37950.1.1.6.1.1.3.1.6',
                        'rx_power' => '1.3.6.1.4.1.37950.1.1.6.1.1.3.1.7',
                        'olt_rx_power' => '1.3.6.1.4.1.37950.1.1.6.1.1.3.1.8',
                        'distance' => '1.3.6.1.4.1.37950.1.1.6.1.1.12.1.3',
                        'onu_mac' => '1.3.6.1.4.1.37950.1.1.6.1.8.1.1.5',
                    ],
                ],
                // VSOL EPON (V1600D tree, 37950.1.1.5.12) — per EPON MIB:
                //   onuListTable .1.9.1.5 = ONU MAC (identity)
                //   onuMacTable  .1.26.1.5 = learned customer MACs, indexed pon.onu.n
                //   onuOpmDiagTable .2.1.13: col 6 txPower, col 7 rxPower
                //   onuRecievePowerTable .1.28.1.3 = OLT Rx of the ONU
                //   onuRttTable .1.17.1.3 = distance
                // UNCONFIRMED on hardware — verify with Diagnostics.
                'epon' => [
                    'power_divisor' => 1,
                    'oids' => [
                        'serial' => '1.3.6.1.4.1.37950.1.1.5.12.1.9.1.5',
                        'onu_mac' => '1.3.6.1.4.1.37950.1.1.5.12.1.9.1.5',
                        'mac' => '1.3.6.1.4.1.37950.1.1.5.12.1.26.1.5',
                        'tx_power' => '1.3.6.1.4.1.37950.1.1.5.12.2.1.13.1.6',
                        'rx_power' => '1.3.6.1.4.1.37950.1.1.5.12.2.1.13.1.7',
                        'olt_rx_power' => '1.3.6.1.4.1.37950.1.1.5.12.1.28.1.3',
                        'distance' => '1.3.6.1.4.1.37950.1.1.5.12.1.17.1.3',
                    ],
                ],
            ],
        ],

        'cdata' => [
            'driver' => CDataDriver::class,
            'power_divisor' => 100, // typical for C-Data (dBm × 100); confirm on hardware
            'distance_unit' => 'm',
            'mac_fdb' => ['q_bridge', 'd_bridge'],

            // Placeholder: ONU online/offline + ports still come from standard
            // MIBs. C-Data enterprise is 17409 (some V3 firmwares use 34592) and
            // the ONU/optical tree varies by model — run the Diagnostics page
            // (ships a C-Data discovery probe), then fill these OIDs and, if
            // the table is indexed by [pon.onu], the gpon/epon overrides below.
            'oids' => [],

            'pon_types' => [
                'gpon' => ['oids' => []],
                'epon' => ['oids' => []],
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
