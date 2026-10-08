<?php

namespace App\Services\Snmp;

/**
 * Catalogue of SNMP subtrees worth walking when discovering / verifying a
 * vendor's ONU tables. Used by the Diagnostics page and `olt:snmp-debug`.
 * Labels are human-readable; values are numeric base OIDs.
 */
class SnmpProbeCatalog
{
    /** @return array<string, string> label => OID, common to every vendor */
    public static function common(): array
    {
        return [
            'sysDescr' => '1.3.6.1.2.1.1.1',
            'sysObjectID' => '1.3.6.1.2.1.1.2',
            'IF-MIB ifDescr (interface names)' => '1.3.6.1.2.1.2.2.1.2',
            'IF-MIB ifName (real port names)' => '1.3.6.1.2.1.31.1.1.1.1',
            'IF-MIB ifAlias (interface aliases)' => '1.3.6.1.2.1.31.1.1.1.18',
            'IF-MIB ifOperStatus (1=up 2=down)' => '1.3.6.1.2.1.2.2.1.8',
            'Q-BRIDGE dot1qTpFdbPort (learned MAC → bridge port)' => BridgeFdbResolver::OID_Q_BRIDGE_FDB_PORT,
            'BRIDGE dot1dTpFdbPort (learned MAC → bridge port, old)' => BridgeFdbResolver::OID_D_BRIDGE_FDB_PORT,
            'BRIDGE dot1dBasePortIfIndex (bridge port → ifIndex)' => BridgeFdbResolver::OID_BASE_PORT_IFINDEX,
        ];
    }

    /** @return array<string, string> label => OID */
    public static function vendor(string $vendor): array
    {
        return match (strtolower($vendor)) {
            'huawei' => [
                'Huawei .43.1.3 — ONT serial' => '1.3.6.1.4.1.2011.6.128.1.1.2.43.1.3',
                'Huawei .43.1.9 — ONT description' => '1.3.6.1.4.1.2011.6.128.1.1.2.43.1.9',
                'Huawei .45.1.4 — ONT equipment id (model)' => '1.3.6.1.4.1.2011.6.128.1.1.2.45.1.4',
                'Huawei .45.1.10 — ONT MAC' => '1.3.6.1.4.1.2011.6.128.1.1.2.45.1.10',
                'Huawei .46.1.15 — run status (1 online 2 offline)' => '1.3.6.1.4.1.2011.6.128.1.1.2.46.1.15',
                'Huawei .46.1.20 — distance / ranging (m)' => '1.3.6.1.4.1.2011.6.128.1.1.2.46.1.20',
                'Huawei .46.1.21 — learned MAC count' => '1.3.6.1.4.1.2011.6.128.1.1.2.46.1.21',
                'Huawei .46.1.22 — last UP time (DateAndTime hex)' => '1.3.6.1.4.1.2011.6.128.1.1.2.46.1.22',
                'Huawei .46.1.23 — last DOWN time (DateAndTime hex)' => '1.3.6.1.4.1.2011.6.128.1.1.2.46.1.23',
                'Huawei .46.1.24 — last DOWN cause (enum)' => '1.3.6.1.4.1.2011.6.128.1.1.2.46.1.24',
                'Huawei .51.1.3 — ONT Tx power ×100 (MA5800 / V800R019+)' => '1.3.6.1.4.1.2011.6.128.1.1.2.51.1.3',
                'Huawei .51.1.4 — ONT Rx power ×100' => '1.3.6.1.4.1.2011.6.128.1.1.2.51.1.4',
                'Huawei .51.1.6 — OLT Rx of ONT ×100' => '1.3.6.1.4.1.2011.6.128.1.1.2.51.1.6',
                'Huawei LINE-COMMON .158 …2.1.16 — ONT Tx (alt table)' => '1.3.6.1.4.1.2011.6.158.1.1.1.2.1.16',
                'Huawei LINE-COMMON .158 …2.1.21 — ONT Rx (alt table)' => '1.3.6.1.4.1.2011.6.158.1.1.1.2.1.21',
                'Huawei LINE-COMMON .158 …2.1.26 — OLT Rx of ONT (alt table)' => '1.3.6.1.4.1.2011.6.158.1.1.1.2.1.26',
                'Huawei LINE-COMMON .158 ONT DDM table (whole)' => '1.3.6.1.4.1.2011.6.158.1.1.1.2',
                'Huawei .49.1.4 — ONT IP-config phy address' => '1.3.6.1.4.1.2011.6.128.1.1.2.49.1.4',
            ],
            'bdcom' => [
                'BDCOM GPON status .10.3.3.1.2 — serial' => '1.3.6.1.4.1.3320.10.3.3.1.2',
                'BDCOM GPON status .10.3.3.1.4 — run status (3 active)' => '1.3.6.1.4.1.3320.10.3.3.1.4',
                'BDCOM GPON optical .10.3.4.1.2 — rx ×10' => '1.3.6.1.4.1.3320.10.3.4.1.2',
                'BDCOM GPON optical .10.3.4.1.3 — tx ×10' => '1.3.6.1.4.1.3320.10.3.4.1.3',
                'BDCOM GPON optical .10.3.4.1.4 — distance (×20 m)' => '1.3.6.1.4.1.3320.10.3.4.1.4',
                'BDCOM GPON optical .10.3.4.1.5 — uptime (min)' => '1.3.6.1.4.1.3320.10.3.4.1.5',
                'BDCOM GPON info .10.3.1.1.2 — vendor id' => '1.3.6.1.4.1.3320.10.3.1.1.2',
                'BDCOM GPON info .10.3.1.1.3 — version/model' => '1.3.6.1.4.1.3320.10.3.1.1.3',
                'BDCOM GPON info .10.3.1.1.4 — serial' => '1.3.6.1.4.1.3320.10.3.1.1.4',
                'BDCOM GPON info .10.3.1.1.19 — sysUpTime' => '1.3.6.1.4.1.3320.10.3.1.1.19',
                'BDCOM GPON info .10.3.1.1.28 — ONU MAC' => '1.3.6.1.4.1.3320.10.3.1.1.28',
                'BDCOM GPON info .10.3.1.1.33 — distance' => '1.3.6.1.4.1.3320.10.3.1.1.33',
                'BDCOM NMS-MAC fdbReadByPort (ifIndex.vlan.mac)' => BridgeFdbResolver::OID_BDCOM_FDB,
                'BDCOM EPON onu .101.10.1.1.2 — module id (model)' => '1.3.6.1.4.1.3320.101.10.1.1.2',
                'BDCOM EPON onu .101.10.1.1.3 — ONU MAC' => '1.3.6.1.4.1.3320.101.10.1.1.3',
                'BDCOM EPON onu .101.10.1.1.26 — status' => '1.3.6.1.4.1.3320.101.10.1.1.26',
                'BDCOM EPON onu .101.10.1.1.27 — distance' => '1.3.6.1.4.1.3320.101.10.1.1.27',
                'BDCOM EPON .101.10.5 — ONU optical table (probe)' => '1.3.6.1.4.1.3320.101.10.5',
                'BDCOM EPON .101.108.1.3 — OLT rx per ONU ×10' => '1.3.6.1.4.1.3320.101.108.1.3',
                'BDCOM EPON .101.10 — whole ONU group (probe, large)' => '1.3.6.1.4.1.3320.101.10',
            ],
            'vsol' => [
                'VSOL G sta .6.1.1.1.1.5 — phase status' => '1.3.6.1.4.1.37950.1.1.6.1.1.1.1.5',
                'VSOL G optical .6.1.1.3.1.6 — tx dBm' => '1.3.6.1.4.1.37950.1.1.6.1.1.3.1.6',
                'VSOL G optical .6.1.1.3.1.7 — rx dBm' => '1.3.6.1.4.1.37950.1.1.6.1.1.3.1.7',
                'VSOL G optical .6.1.1.3.1.8 — OLT rx dBm' => '1.3.6.1.4.1.37950.1.1.6.1.1.3.1.8',
                'VSOL G detail .6.1.1.4.1.5 — serial' => '1.3.6.1.4.1.37950.1.1.6.1.1.4.1.5',
                'VSOL G detail .6.1.1.4.1.17 — model' => '1.3.6.1.4.1.37950.1.1.6.1.1.4.1.17',
                'VSOL G detail .6.1.1.4.1.24 — description' => '1.3.6.1.4.1.37950.1.1.6.1.1.4.1.24',
                'VSOL G rtt .6.1.1.12.1.3 — distance' => '1.3.6.1.4.1.37950.1.1.6.1.1.12.1.3',
                'VSOL G pri .6.1.8.1.1.5 — ONU MAC' => '1.3.6.1.4.1.37950.1.1.6.1.8.1.1.5',
                'VSOL E onuList .5.12.1.9.1.5 — ONU MAC' => '1.3.6.1.4.1.37950.1.1.5.12.1.9.1.5',
                'VSOL E onuMac .5.12.1.26.1.5 — learned MACs' => '1.3.6.1.4.1.37950.1.1.5.12.1.26.1.5',
                'VSOL E opm .5.12.2.1.13.1.6 — tx' => '1.3.6.1.4.1.37950.1.1.5.12.2.1.13.1.6',
                'VSOL E opm .5.12.2.1.13.1.7 — rx' => '1.3.6.1.4.1.37950.1.1.5.12.2.1.13.1.7',
                'VSOL E recvPower .5.12.1.28.1.3 — OLT rx' => '1.3.6.1.4.1.37950.1.1.5.12.1.28.1.3',
                'VSOL E rtt .5.12.1.17.1.3 — distance' => '1.3.6.1.4.1.37950.1.1.5.12.1.17.1.3',
                'VSOL enterprise root 37950.1.1 (probe, large)' => '1.3.6.1.4.1.37950.1.1',
            ],
            'cdata' => [
                'C-Data ent 17409 (probe, large)' => '1.3.6.1.4.1.17409',
                'C-Data ent 34592 (probe, large)' => '1.3.6.1.4.1.34592',
            ],
            default => [],
        };
    }

    /** @return array<string, string> label => OID */
    public static function all(string $vendor): array
    {
        return array_merge(self::common(), self::vendor($vendor));
    }
}
