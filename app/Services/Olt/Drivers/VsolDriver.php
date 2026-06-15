<?php

namespace App\Services\Olt\Drivers;

use App\Enums\OnuStatus;
use App\Models\Olt;
use App\Services\Olt\Data\OnuInfo;
use App\Services\Olt\Data\PortInfo;
use App\Services\Olt\Simulator\OltSimulator;
use App\Services\Snmp\SnmpClient;

/**
 * VSOL GPON OLTs (Broadcom-based firmware, enterprise 37950).
 *
 * VSOL creates per-ONU virtual interfaces in IF-MIB with names like
 * "GPON01ONU5" (PON port 1, ONU 5). We enumerate ONUs from ifDescr
 * and derive status from ifOperStatus — the vendor-specific OID tree
 * (.37950.1.1.5.10) is a port-management table, not an ONU walk.
 */
class VsolDriver extends AbstractVendorDriver
{
    protected function vendorKey(): string { return 'vsol'; }

    public function fetchPorts(Olt $olt): array
    {
        if ($olt->shouldSimulate()) {
            return app(OltSimulator::class)->ports($olt);
        }

        $std    = config('olt.standard');
        $client = SnmpClient::forOlt($olt);
        $descr  = $client->walk($std['ifDescr']);
        $oper   = $client->walk($std['ifOperStatus']);
        $admin  = $client->walk($std['ifAdminStatus']);
        $client->close();

        $ports = [];
        foreach ($descr as $index => $name) {
            // VSOL PON ports: "GPON0/1" through "GPON0/16".
            // Exclude ONU virtual interfaces ("GPON01ONU1"), GE ports, VLANs.
            if (! preg_match('/^GPON0\/\d+$/i', $name)) {
                continue;
            }
            $ports[] = new PortInfo(
                portIndex: $index,
                name: $name,
                adminStatus: $this->mapIfStatus($admin[$index] ?? null),
                operStatus: $this->mapIfStatus($oper[$index] ?? null),
            );
        }

        return $ports;
    }

    public function fetchOnus(Olt $olt): array
    {
        if ($olt->shouldSimulate()) {
            return app(OltSimulator::class)->onus($olt);
        }

        $std    = config('olt.standard');
        $client = SnmpClient::forOlt($olt);
        $descr  = $client->walk($std['ifDescr']);
        $oper   = $client->walk($std['ifOperStatus']);
        $client->close();

        // Build PON port name → ifIndex map for portIndex linking.
        // e.g. "GPON0/1" → "13", "GPON0/13" → "25"
        $portNameToIndex = [];
        foreach ($descr as $ifIndex => $name) {
            if (preg_match('/^GPON0\/(\d+)$/i', $name)) {
                $portNameToIndex[$name] = (string) $ifIndex;
            }
        }

        $onus = [];
        foreach ($descr as $ifIndex => $name) {
            // Match VSOL ONU virtual interface names: GPON01ONU1, GPON013ONU55, etc.
            // Capture port number (may have leading zero: 01, 010, 013) and ONU ID.
            if (! preg_match('/^GPON0*(\d+)ONU(\d+)$/i', $name, $m)) {
                continue;
            }

            $portNum   = (int) $m[1];
            $onuId     = (int) $m[2];
            $portName  = 'GPON0/' . $portNum;
            $portIndex = $portNameToIndex[$portName] ?? null;
            // ONU sub-port name in standard format: "GPON0/1:5"
            $onuName   = $portName . ':' . $onuId;

            $status = ((int) ($oper[$ifIndex] ?? 2)) === 1
                ? OnuStatus::Online
                : OnuStatus::Offline;

            $onus[] = new OnuInfo(
                onuIndex:     (string) $ifIndex,
                portIndex:    $portIndex,
                serialNumber: null, // Vendor OIDs not confirmed for this firmware
                macAddress:   null,
                name:         $onuName,
                description:  null,
                status:       $status,
                rxPower:      null,
                txPower:      null,
                distance:     null,
                onlineSince:  null,
            );
        }

        return $onus;
    }
}
