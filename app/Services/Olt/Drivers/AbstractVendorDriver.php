<?php

namespace App\Services\Olt\Drivers;

use App\Enums\OnuStatus;
use App\Models\Olt;
use App\Services\Olt\Contracts\VendorDriver;
use App\Services\Olt\Data\OnuInfo;
use App\Services\Olt\Data\PortInfo;
use App\Services\Olt\Data\SystemInfo;
use App\Services\Olt\Simulator\OltSimulator;
use App\Services\Snmp\SnmpClient;

/**
 * Shared SNMP logic for all vendor drivers. System info and ports come from
 * standard RFC1213/IF-MIB OIDs (vendor independent). ONU enumeration is driven
 * by the per-vendor OID map in config/olt.php, with vendor-specific quirks
 * (status codes, power scaling) overridable by subclasses.
 */
abstract class AbstractVendorDriver implements VendorDriver
{
    /** Config key under olt.vendors.* */
    abstract protected function vendorKey(): string;

    protected function config(): array
    {
        return config("olt.vendors.{$this->vendorKey()}", []);
    }

    protected function oids(): array
    {
        return $this->config()['oids'] ?? [];
    }

    protected function powerDivisor(): int
    {
        return (int) ($this->config()['power_divisor'] ?? 100);
    }

    public function probe(Olt $olt): bool
    {
        if ($olt->shouldSimulate()) {
            return true;
        }

        $client = SnmpClient::forOlt($olt);
        $ok = $client->ping();
        $client->close();

        return $ok;
    }

    public function fetchSystem(Olt $olt): SystemInfo
    {
        if ($olt->shouldSimulate()) {
            return app(OltSimulator::class)->system($olt);
        }

        $std = config('olt.standard');
        $client = SnmpClient::forOlt($olt);

        $info = new SystemInfo(
            description: $client->get($std['sysDescr']),
            name: $client->get($std['sysName']),
            location: $client->get($std['sysLocation']),
            uptimeTicks: ($u = $client->get($std['sysUpTime'])) !== null ? (int) $u : null,
        );

        $client->close();

        return $info;
    }

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
            // Only keep PON-looking interfaces; uplinks/management are ignored.
            if (! $this->looksLikePonPort($name)) {
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

        $oids = $this->oids();
        if (empty($oids['serial']) && empty($oids['run_status'])) {
            return []; // no vendor map → nothing to enumerate
        }

        $client = SnmpClient::forOlt($olt);

        $serials = isset($oids['serial']) ? $client->walk($oids['serial']) : [];
        $status = isset($oids['run_status']) ? $client->walk($oids['run_status']) : [];
        $rx = isset($oids['rx_power']) ? $client->walk($oids['rx_power']) : [];
        $tx = isset($oids['tx_power']) ? $client->walk($oids['tx_power']) : [];
        $dist = isset($oids['distance']) ? $client->walk($oids['distance']) : [];
        $mac = isset($oids['mac']) ? $client->walk($oids['mac']) : [];
        $desc = isset($oids['description']) ? $client->walk($oids['description']) : [];

        $client->close();

        // Union of all ONU indexes we saw across tables.
        $indexes = array_unique(array_merge(
            array_keys($serials), array_keys($status), array_keys($rx)
        ));

        $onus = [];
        foreach ($indexes as $index) {
            $onus[] = new OnuInfo(
                onuIndex: (string) $index,
                portIndex: $this->derivePortIndex((string) $index),
                serialNumber: $this->normaliseSerial($serials[$index] ?? null),
                macAddress: $this->normaliseMac($mac[$index] ?? null),
                description: $desc[$index] ?? null,
                status: $this->mapStatus($status[$index] ?? null),
                rxPower: $this->parsePower($rx[$index] ?? null),
                txPower: $this->parsePower($tx[$index] ?? null),
                distance: isset($dist[$index]) ? (float) $dist[$index] : null,
            );
        }

        return $onus;
    }

    public function fetchOnu(Olt $olt, string $onuIndex): ?OnuInfo
    {
        if ($olt->shouldSimulate()) {
            return app(OltSimulator::class)->onu($olt, $onuIndex);
        }

        foreach ($this->fetchOnus($olt) as $onu) {
            if ($onu->onuIndex === $onuIndex) {
                return $onu;
            }
        }

        return null;
    }

    // ---- Overridable vendor quirks -------------------------------------

    /**
     * Map a vendor run-status value to a normalised ONU status. Defaults to the
     * common net-snmp truthiness: 1 = up/online, anything else = offline.
     */
    protected function mapStatus(?string $raw): OnuStatus
    {
        if ($raw === null || $raw === '') {
            return OnuStatus::Unknown;
        }

        return match ((int) $raw) {
            1 => OnuStatus::Online,
            2 => OnuStatus::Offline,
            3 => OnuStatus::Losi,
            4 => OnuStatus::Dying,
            default => OnuStatus::Offline,
        };
    }

    protected function parsePower(?string $raw): ?float
    {
        if ($raw === null || $raw === '' || ! is_numeric($raw)) {
            return null;
        }

        $value = (float) $raw / $this->powerDivisor();

        // Treat obviously-invalid sentinels (e.g. 2147483647) as "no reading".
        if (abs($value) > 60) {
            return null;
        }

        return round($value, 2);
    }

    protected function normaliseMac(?string $raw): ?string
    {
        if (! $raw) {
            return null;
        }

        // Already colon-formatted?
        if (str_contains($raw, ':')) {
            return strtoupper($raw);
        }

        // Hex blob → MAC.
        $hex = preg_replace('/[^0-9A-Fa-f]/', '', $raw);
        if (strlen($hex) === 12) {
            return strtoupper(implode(':', str_split($hex, 2)));
        }

        return strtoupper($raw);
    }

    protected function normaliseSerial(?string $raw): ?string
    {
        return $raw ? strtoupper(trim($raw)) : null;
    }

    /**
     * Many vendors encode the ONU index as "<portIfIndex>.<onuId>".
     */
    protected function derivePortIndex(string $onuIndex): ?string
    {
        return str_contains($onuIndex, '.')
            ? strtok($onuIndex, '.')
            : null;
    }

    protected function looksLikePonPort(?string $name): bool
    {
        if (! $name) {
            return false;
        }

        return (bool) preg_match('/(pon|gpon|epon|xpon)/i', $name);
    }

    protected function mapIfStatus(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        return ((int) $raw) === 1 ? 'up' : 'down';
    }
}
