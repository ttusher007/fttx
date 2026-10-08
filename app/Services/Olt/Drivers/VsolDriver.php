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
    protected function vendorKey(): string
    {
        return 'vsol';
    }

    /** Matches "GPON0/1", "EPON0/1" and "PON0/1" physical PON ports. */
    private const PORT_RE = '/^(?:GPON|EPON|XPON|PON)0\/(\d+)$/i';

    /** Matches per-ONU virtual interfaces "GPON01ONU5", "EPON013ONU55", "PON1ONU5". */
    private const ONU_RE = '/^(?:GPON|EPON|XPON|PON)0*(\d+)ONU(\d+)$/i';

    public function fetchPorts(Olt $olt): array
    {
        if ($olt->shouldSimulate()) {
            return app(OltSimulator::class)->ports($olt);
        }

        $std = config('olt.standard');
        $client = SnmpClient::forOlt($olt);
        $descr = $client->walk($std['ifDescr']);
        $oper = $client->walk($std['ifOperStatus']);
        $admin = $client->walk($std['ifAdminStatus']);
        $client->close();

        $ports = [];
        foreach ($descr as $index => $name) {
            if (! preg_match(self::PORT_RE, trim($name))) {
                continue;
            }
            $ports[] = new PortInfo(
                portIndex: $index,
                name: trim($name),
                adminStatus: $this->mapIfStatus($admin[$index] ?? null),
                operStatus: $this->mapIfStatus($oper[$index] ?? null),
            );
        }

        return $ports;
    }

    protected function looksLikePonPort(?string $name): bool
    {
        return $name !== null && (bool) preg_match(self::PORT_RE, trim($name));
    }

    public function fetchOnus(Olt $olt): array
    {
        $this->activeOlt = $olt;
        $this->notes = [];

        if ($olt->shouldSimulate()) {
            return app(OltSimulator::class)->onus($olt);
        }

        $std = config('olt.standard');
        $client = SnmpClient::forOlt($olt);
        $descr = $client->walk($std['ifDescr']);
        $oper = $client->walk($std['ifOperStatus']);
        $alias = $client->walk($std['ifAlias']); // ifAlias — operator descriptions

        // Vendor enrichment tables (config-driven, pon_type aware). Each is
        // indexed by "pon.onu", matching the ifDescr-derived key below.
        $tables = $this->walkOnuTables($client, $this->oids());
        $fdb = $this->resolveFdbMacs($client);

        $client->close();

        // Learned-MAC tables indexed pon.onu.n (VSOL EPON onuMacTable): group per ONU.
        $macLists = [];
        foreach ($tables['mac'] ?? [] as $index => $value) {
            $parts = explode('.', (string) $index);
            if (count($parts) >= 3 && ($mac = $this->normaliseMac($value))) {
                $macLists[$parts[0].'.'.$parts[1]][] = $mac;
            }
        }

        // Build PON port name → ifIndex map (e.g. "GPON0/1" → "13").
        $portNameToIndex = [];
        foreach ($descr as $ifIndex => $name) {
            if (preg_match(self::PORT_RE, trim($name), $m)) {
                $portNameToIndex[(int) $m[1]] = (string) $ifIndex;
            }
        }

        $onus = [];
        foreach ($descr as $ifIndex => $name) {
            $name = trim($name);
            if (! preg_match(self::ONU_RE, $name, $m)) {
                continue;
            }

            $tech = strtoupper(preg_replace('/\d.*$/', '', $name));
            $portNum = (int) $m[1];
            $onuId = (int) $m[2];
            $portName = "{$tech}0/{$portNum}";
            $portIndex = $portNameToIndex[$portNum] ?? null;
            $onuName = $portName.':'.$onuId;

            // Vendor tables are keyed by [ponIndex.onuIndex] = "portNum.onuId".
            $key = $portNum.'.'.$onuId;

            $status = ((int) ($oper[$ifIndex] ?? 2)) === 1
                ? OnuStatus::Online
                : OnuStatus::Offline;

            $onu = $this->buildOnu(
                onuIndex: (string) $ifIndex,
                portIndex: $portIndex,
                name: $onuName,
                status: $status,
                tables: $tables,
                key: $key,
            );

            // Prefer the vendor ONU description (keyed by pon.onu); fall back to
            // IF-MIB ifAlias (often blank or just the port name on VSOL).
            $aliasText = $this->cleanText($alias[$ifIndex] ?? null);
            if ($aliasText !== null && strcasecmp($aliasText, $onuName) === 0) {
                $aliasText = null;
            }
            $descText = $onu->description;
            if ($descText !== null && strcasecmp($descText, $onuName) === 0) {
                $descText = null;
            }

            $changes = ['description' => $descText ?? $aliasText];

            if (! $onu->macAddress && ! empty($macLists[$key])) {
                $own = array_filter([$onu->onuMac, $this->normaliseMac($onu->serialNumber)]);
                $macs = array_values(array_filter($macLists[$key], fn ($mac) => ! in_array($mac, $own, true)));
                if ($macs) {
                    $changes['macAddress'] = $macs[0];
                    $changes['macCount'] = count($macs);
                    $changes['macSource'] = 'snmp';
                }
            }

            $onus[] = $onu->with($changes);
        }

        return $this->applyFdbMacs($onus, $fdb);
    }

    /**
     * VSOL optical power columns are OCTET STRINGs already in dBm. Offline ONUs
     * report "NULL", "N/A", "-" or 0.00 — treat those as no reading.
     */
    protected function parsePower(?string $raw): ?float
    {
        if ($raw === null) {
            return null;
        }
        $raw = trim($raw);
        if ($raw === '' || ! is_numeric($raw) || (float) $raw === 0.0) {
            return null;
        }

        return parent::parsePower($raw);
    }
}
