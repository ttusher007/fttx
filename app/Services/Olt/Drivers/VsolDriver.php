<?php

namespace App\Services\Olt\Drivers;

use App\Enums\OnuStatus;
use App\Models\Olt;
use App\Services\Olt\Data\OnuInfo;
use App\Services\Olt\Data\PortInfo;
use App\Services\Olt\Simulator\OltSimulator;
use App\Services\Snmp\SnmpClient;

/**
 * VSOL GPON/EPON OLTs (V1600D/V1600G platforms, enterprise 37950).
 *
 * VSOL creates per-ONU virtual interfaces in IF-MIB with names like
 * "GPON01ONU5" (PON port 1, ONU 5) / "EPON01ONU5". The online/offline state
 * (always reliable across firmwares) is read from ifOperStatus — this is the
 * enumeration "spine".
 *
 * The vendor ONU tables (config: olt.vendors.vsol.pon_types.{gpon|epon}.oids)
 * are then walked to ENRICH each ONU with serial, optical power, MAC and
 * distance. Those tables are indexed by [ponIndex, onuIndex], i.e. the walk
 * returns a "pon.onu" trailing index (e.g. "1.5") which we join to the ONU we
 * found in ifDescr. If a vendor table is empty (older firmware) the ONU still
 * shows up with its online/offline status — no regression.
 */
class VsolDriver extends AbstractVendorDriver
{
    protected function vendorKey(): string { return 'vsol'; }

    /** Matches both "GPON0/1" and "EPON0/1" physical PON ports. */
    private const PORT_RE = '/^(?:GPON|EPON)0\/(\d+)$/i';

    /** Matches per-ONU virtual interfaces "GPON01ONU5", "EPON013ONU55", etc. */
    private const ONU_RE = '/^(?:GPON|EPON)0*(\d+)ONU(\d+)$/i';

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
            if (! preg_match(self::PORT_RE, $name)) {
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
        $this->activeOlt = $olt;

        if ($olt->shouldSimulate()) {
            return app(OltSimulator::class)->onus($olt);
        }

        $std    = config('olt.standard');
        $client = SnmpClient::forOlt($olt);
        $descr  = $client->walk($std['ifDescr']);
        $oper   = $client->walk($std['ifOperStatus']);
        $alias  = $client->walk('1.3.6.1.2.1.31.1.1.1.18'); // ifAlias — operator descriptions

        // Vendor enrichment tables (config-driven, pon_type aware). Each is
        // indexed by "pon.onu", matching the ifDescr-derived key below.
        $oids     = $this->oids();
        $serials  = isset($oids['serial'])      ? $client->walk($oids['serial'])      : [];
        $macs     = isset($oids['mac'])         ? $client->walk($oids['mac'])         : [];
        $rx       = isset($oids['rx_power'])    ? $client->walk($oids['rx_power'])    : [];
        $tx       = isset($oids['tx_power'])    ? $client->walk($oids['tx_power'])    : [];
        $dist     = isset($oids['distance'])    ? $client->walk($oids['distance'])    : [];
        $descs    = isset($oids['description']) ? $client->walk($oids['description']) : [];

        $client->close();

        // Build PON port name → ifIndex map (e.g. "GPON0/1" → "13").
        $portNameToIndex = [];
        foreach ($descr as $ifIndex => $name) {
            if (preg_match(self::PORT_RE, $name)) {
                // Re-derive the canonical "<TECH>0/<n>" key without leading zeros.
                $portNameToIndex[strtoupper(trim($name))] = (string) $ifIndex;
            }
        }

        $onus = [];
        foreach ($descr as $ifIndex => $name) {
            if (! preg_match(self::ONU_RE, $name, $m)) {
                continue;
            }

            $tech      = stripos($name, 'EPON') === 0 ? 'EPON' : 'GPON';
            $portNum   = (int) $m[1];
            $onuId     = (int) $m[2];
            $portName  = "{$tech}0/{$portNum}";
            $portIndex = $portNameToIndex[$portName] ?? null;
            $onuName   = $portName . ':' . $onuId;

            // Vendor tables are keyed by [ponIndex.onuIndex] = "portNum.onuId".
            $key = $portNum . '.' . $onuId;

            $status = ((int) ($oper[$ifIndex] ?? 2)) === 1
                ? OnuStatus::Online
                : OnuStatus::Offline;

            $onus[] = new OnuInfo(
                onuIndex:     (string) $ifIndex,
                portIndex:    $portIndex,
                serialNumber: $this->normaliseSerial($serials[$key] ?? null),
                macAddress:   $this->normaliseMac($macs[$key] ?? null),
                name:         $onuName,
                // Prefer the vendor ONU description (keyed by pon.onu); fall back
                // to IF-MIB ifAlias (often blank on VSOL).
                description:  $this->cleanText($descs[$key] ?? null) ?? $this->cleanText($alias[$ifIndex] ?? null),
                status:       $status,
                rxPower:      $this->parsePower($rx[$key] ?? null),
                txPower:      $this->parsePower($tx[$key] ?? null),
                distance:     $this->parseDistance($dist[$key] ?? null),
                onlineSince:  null,
            );
        }

        return $onus;
    }

    /**
     * VSOL optical power columns are OCTET STRINGs already in dBm. Some
     * firmwares send the literal string "N/A" (or "-") for offline ONUs;
     * strip those before the numeric parse in the parent.
     */
    protected function parsePower(?string $raw): ?float
    {
        if ($raw === null) {
            return null;
        }
        $raw = trim($raw);
        if ($raw === '' || ! is_numeric($raw)) {
            return null;
        }

        return parent::parsePower($raw);
    }

    /** Drop empty / placeholder descriptions so they don't overwrite real names. */
    private function cleanText(?string $raw): ?string
    {
        $raw = trim((string) $raw);

        if ($raw === '' || in_array(strtoupper($raw), ['N/A', 'NULL', '-', 'NA'], true)) {
            return null;
        }

        return $raw;
    }
}
