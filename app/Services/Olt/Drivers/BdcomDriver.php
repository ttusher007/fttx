<?php

namespace App\Services\Olt\Drivers;

use App\Enums\OnuStatus;
use App\Models\Olt;
use App\Services\Olt\Data\OnuInfo;
use App\Services\Snmp\SnmpClient;

/**
 * BDCOM EPON/GPON OLTs (P3310, P3608, GP3600, etc.).
 *
 * Every ONU is an IF-MIB interface ("GPON0/1:5" / "EPON0/2:17"), and the vendor
 * tables are indexed by that ONU ifIndex. Customer MACs come from the bridge
 * FDB (NMS-MAC-MIB fdbReadByPortTable), joined on the same ifIndex.
 */
class BdcomDriver extends AbstractVendorDriver
{
    protected function vendorKey(): string
    {
        return 'bdcom';
    }

    protected function looksLikePonPort(?string $name): bool
    {
        if (! $name || str_contains($name, ':')) {
            return false;
        }

        // Physical PON uplink only — GPON0/8, EPON0/1, etc.
        return (bool) preg_match('/^(GPON|EPON|gpon|epon)\d+\/\d+$/', trim($name));
    }

    protected function mapStatus(?string $raw): OnuStatus
    {
        if ($raw === null || $raw === '') {
            return OnuStatus::Unknown;
        }

        $value = (int) $raw;

        if ($this->usesGponOids()) {
            // GP3600 GPON: 3=active, 0=off-line, 1=inactive, 2=disable
            return match ($value) {
                3 => OnuStatus::Online,
                0, 1, 2 => OnuStatus::Offline,
                default => OnuStatus::Unknown,
            };
        }

        // EPON nmsepononuTable onuStatus: 0 authenticated, 1 registered,
        // 2 deregistered, 3 auto_config, 4 lost, 5 standby.
        return match ($value) {
            0, 1, 3 => OnuStatus::Online,
            2, 5 => OnuStatus::Offline,
            4 => OnuStatus::Losi,
            default => OnuStatus::Unknown,
        };
    }

    public function fetchOnus(Olt $olt): array
    {
        $this->activeOlt = $olt;

        [$onuToPort, $onuNames] = $this->buildInterfaceMaps($olt);

        $onus = parent::fetchOnus($olt);

        if (empty($onus)) {
            return $onus;
        }

        // Vendor tables sometimes include PON-port rows — keep only real ONU interfaces.
        if (! empty($onuToPort)) {
            $onus = array_values(array_filter(
                $onus,
                fn (OnuInfo $onu) => isset($onuToPort[$onu->onuIndex]),
            ));
        }

        return array_map(function (OnuInfo $onu) use ($onuToPort, $onuNames) {
            $name = $onuNames[$onu->onuIndex] ?? null;

            return $onu->with([
                'portIndex' => $onuToPort[$onu->onuIndex] ?? $onu->portIndex,
                'name' => $name,
                'description' => $onu->description ?: $name,
            ]);
        }, $onus);
    }

    /**
     * Map ONU ifIndex → parent PON ifIndex using IF-MIB interface names.
     *
     * @return array{0: array<string, string>, 1: array<string, string>}
     */
    private function buildInterfaceMaps(Olt $olt): array
    {
        if ($olt->shouldSimulate()) {
            return [[], []];
        }

        $client = SnmpClient::forOlt($olt);
        $descr = $client->walk(config('olt.standard.ifDescr'));
        $client->close();

        $ponPorts = [];
        $onuToPort = [];
        $onuNames = [];

        foreach ($descr as $index => $name) {
            $name = trim($name);

            if (preg_match('/^(GPON|EPON|gpon|epon)(\d+\/\d+)$/i', $name, $m)) {
                $ponPorts[strtoupper($m[1].$m[2])] = (string) $index;

                continue;
            }

            if (preg_match('/^(GPON|EPON|gpon|epon)(\d+\/\d+):(\d+)$/i', $name, $m)) {
                $parentKey = strtoupper($m[1].$m[2]);
                $onuToPort[(string) $index] = $ponPorts[$parentKey] ?? null;
                $onuNames[(string) $index] = strtoupper($name);
            }
        }

        return [$onuToPort, $onuNames];
    }

    private function usesGponOids(): bool
    {
        return str_contains($this->oids()['run_status'] ?? '', '3320.10.3.3');
    }
}
