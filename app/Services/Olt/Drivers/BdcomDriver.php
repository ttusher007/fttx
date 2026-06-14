<?php

namespace App\Services\Olt\Drivers;

use App\Enums\OnuStatus;
use App\Models\Olt;
use App\Services\Olt\Data\OnuInfo;
use App\Services\Snmp\SnmpClient;

/**
 * BDCOM EPON/GPON OLTs (P3310, P3608, GP3600, etc.).
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

        // EPON: 1=up, 2=down, 3=los
        return match ($value) {
            1 => OnuStatus::Online,
            2 => OnuStatus::Offline,
            3 => OnuStatus::Losi,
            default => OnuStatus::Offline,
        };
    }

    public function fetchOnus(Olt $olt): array
    {
        [$onuToPort, $onuNames] = $this->buildInterfaceMaps($olt);

        $onus = parent::fetchOnus($olt);

        if (empty($onus)) {
            return $onus;
        }

        // GP3600 status table includes PON ifIndexes — keep only real ONU interfaces.
        if ($this->usesGponOids()) {
            $onus = array_values(array_filter(
                $onus,
                fn (OnuInfo $onu) => isset($onuToPort[$onu->onuIndex]),
            ));
        }

        return array_map(function (OnuInfo $onu) use ($onuToPort, $onuNames) {
            $portIndex = $onuToPort[$onu->onuIndex] ?? $onu->portIndex;
            $name = $onuNames[$onu->onuIndex] ?? null;

            return new OnuInfo(
                onuIndex: $onu->onuIndex,
                portIndex: $portIndex,
                serialNumber: $onu->serialNumber,
                macAddress: $onu->macAddress,
                name: $name,
                description: $onu->description ?: $name,
                status: $onu->status,
                rxPower: $onu->rxPower,
                txPower: $onu->txPower,
                distance: $onu->distance,
                onlineSince: $onu->onlineSince,
            );
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
