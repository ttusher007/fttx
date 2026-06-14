<?php

namespace App\Services\Olt\Simulator;

use App\Enums\OnuStatus;
use App\Models\Olt;
use App\Services\Olt\Data\OnuInfo;
use App\Services\Olt\Data\PortInfo;
use App\Services\Olt\Data\SystemInfo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Produces realistic, stable fake telemetry for OLTs flagged `is_simulated`.
 *
 * ONU topology (port count, serials, MACs) is seeded by the OLT id so it stays
 * stable across syncs, while optical power and online/offline state drift each
 * run — exactly how a real network behaves. This lets the entire app be
 * demonstrated end-to-end without physical hardware.
 */
class OltSimulator
{
    public function system(Olt $olt): SystemInfo
    {
        return new SystemInfo(
            description: ucfirst($olt->vendor).' '.($olt->model ?: 'GPON OLT').' (simulated)',
            name: $olt->name,
            location: $olt->location,
            uptimeTicks: random_int(1_000_000, 900_000_000),
        );
    }

    /**
     * @return array<int, PortInfo>
     */
    public function ports(Olt $olt): array
    {
        [$portCount] = $this->topology($olt);
        $ports = [];

        for ($p = 1; $p <= $portCount; $p++) {
            $ports[] = new PortInfo(
                portIndex: (string) $p,
                name: "GPON 0/{$olt->id}/{$p}",
                adminStatus: 'up',
                operStatus: 'up',
            );
        }

        return $ports;
    }

    /**
     * @return array<int, OnuInfo>
     */
    public function onus(Olt $olt): array
    {
        [$portCount, $perPort] = $this->topology($olt);
        $onus = [];

        foreach (range(1, $portCount) as $port) {
            $count = $perPort[$port];

            for ($i = 1; $i <= $count; $i++) {
                // Stable identity: seed by OLT + port + slot.
                mt_srand($olt->id * 100_000 + $port * 1_000 + $i);
                $serial = strtoupper(substr($olt->vendor, 0, 4)).strtoupper(Str::random(8));
                $mac = $this->fakeMac();
                $distance = mt_rand(120, 19500); // metres

                // Volatile metrics: re-seeded each run.
                mt_srand((int) (microtime(true) * 1000) + $port * 100 + $i);
                $roll = mt_rand(1, 100);
                $status = match (true) {
                    $roll <= 88 => OnuStatus::Online,
                    $roll <= 94 => OnuStatus::Offline,
                    $roll <= 98 => OnuStatus::Losi,
                    default => OnuStatus::Dying,
                };

                $rx = $status === OnuStatus::Online
                    ? round(-15 - (mt_rand(0, 1200) / 100), 2)   // -15 .. -27 dBm
                    : null;
                $tx = $status === OnuStatus::Online
                    ? round(1.5 + (mt_rand(0, 150) / 100), 2)
                    : null;

                $onus[] = new OnuInfo(
                    onuIndex: "{$port}.{$i}",
                    portIndex: (string) $port,
                    serialNumber: $serial,
                    macAddress: $mac,
                    name: null,
                    description: "Customer-{$olt->id}-{$port}-{$i}",
                    status: $status,
                    rxPower: $rx,
                    txPower: $tx,
                    distance: (float) $distance,
                    onlineSince: $status === OnuStatus::Online
                        ? Carbon::now()->subMinutes(mt_rand(5, 86_400))
                        : null,
                );
            }
        }

        mt_srand(); // restore randomness

        return $onus;
    }

    public function onu(Olt $olt, string $onuIndex): ?OnuInfo
    {
        foreach ($this->onus($olt) as $onu) {
            if ($onu->onuIndex === $onuIndex) {
                return $onu;
            }
        }

        return null;
    }

    /**
     * Deterministic per-OLT topology: [portCount, [port => onuCount]].
     *
     * @return array{0:int,1:array<int,int>}
     */
    private function topology(Olt $olt): array
    {
        mt_srand($olt->id);
        $portCount = [8, 16][$olt->id % 2];
        $perPort = [];
        foreach (range(1, $portCount) as $port) {
            $perPort[$port] = mt_rand(0, 48);
        }
        mt_srand();

        return [$portCount, $perPort];
    }

    private function fakeMac(): string
    {
        $parts = [];
        for ($i = 0; $i < 6; $i++) {
            $parts[] = strtoupper(str_pad(dechex(mt_rand(0, 255)), 2, '0', STR_PAD_LEFT));
        }

        return implode(':', $parts);
    }
}
