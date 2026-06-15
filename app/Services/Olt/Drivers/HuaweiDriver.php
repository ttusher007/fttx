<?php

namespace App\Services\Olt\Drivers;

use App\Enums\OnuStatus;
use App\Models\Olt;
use App\Services\Olt\Data\PortInfo;
use App\Services\Olt\Simulator\OltSimulator;
use App\Services\Snmp\SnmpClient;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Huawei SmartAX MA5600T / MA5800 series (hwGpon* MIBs, enterprise 2011).
 */
class HuaweiDriver extends AbstractVendorDriver
{
    protected function vendorKey(): string
    {
        return 'huawei';
    }

    protected function mapStatus(?string $raw): OnuStatus
    {
        // hwGponDeviceOntControlRunStatus: 1 = online, 2 = offline.
        return match ((int) $raw) {
            1 => OnuStatus::Online,
            2 => OnuStatus::Offline,
            default => OnuStatus::Unknown,
        };
    }

    /**
     * Huawei ports all share the same ifDescr ("Huawei-MA5600-V800R018-GPON_UNI").
     * Use ifName instead to get distinct names like "GPON 0/0/0".
     */
    public function fetchPorts(Olt $olt): array
    {
        if ($olt->shouldSimulate()) {
            return app(OltSimulator::class)->ports($olt);
        }

        $std    = config('olt.standard');
        $client = SnmpClient::forOlt($olt);

        $names = $client->walk('1.3.6.1.2.1.31.1.1.1.1'); // ifName
        $oper  = $client->walk($std['ifOperStatus']);
        $admin = $client->walk($std['ifAdminStatus']);
        $client->close();

        $ports = [];
        foreach ($names as $index => $name) {
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

    /** Walk ifName once during fetchOnus so we can build ONU port names. */
    protected function fetchIfNames(SnmpClient $client): array
    {
        return $client->walk('1.3.6.1.2.1.31.1.1.1.1');
    }

    /** Build "GPON 0/0/0:2" from the port's ifName and the ONU ID. */
    protected function buildOnuName(string $onuIndex, ?string $portIndex, array $ifNames): ?string
    {
        if (! $portIndex) {
            return null;
        }

        $parts = explode('.', $onuIndex, 2);
        if (count($parts) !== 2) {
            return null;
        }

        $onuId    = $parts[1];
        $portName = $ifNames[$portIndex] ?? null;

        return $portName ? "{$portName}:{$onuId}" : null;
    }

    /** Huawei returns -1 (integer sentinel) when the ONU is offline or MAC is unavailable. */
    protected function normaliseMac(?string $raw): ?string
    {
        if ($raw === null || $raw === '-1' || $raw === '') {
            return null;
        }

        return parent::normaliseMac($raw);
    }

    /**
     * Huawei GPON serial is 8 bytes: 4-byte ASCII vendor prefix + 4-byte binary serial.
     * cleanValue() hex-encodes non-printable binary → 16-char hex string.
     * We decode the first 8 hex chars to ASCII and keep the last 8 as hex: "HWTC35B9519B".
     */
    protected function normaliseSerial(?string $raw): ?string
    {
        if (! $raw) {
            return null;
        }
        $raw = strtoupper(trim($raw));

        if (preg_match('/^[0-9A-F]{16}$/', $raw)) {
            $vendor = hex2bin(substr($raw, 0, 8));
            if ($vendor !== false && ctype_print($vendor)) {
                return strtoupper($vendor) . substr($raw, 8);
            }
        }

        return $raw;
    }

    /**
     * Huawei online_since is an SNMP DateAndTime OctetString (RFC 2579), returned
     * by net-snmp as a hex string. Format (20 hex chars = 10 bytes):
     *   YYYY MM DD hh mm ss ds [+/-] TZhh [TZmm]
     * e.g. "07EA060D172B2C002B06" → 2026-06-13 23:43:44 +06:00
     */
    protected function parseOnlineSince(?string $raw): ?CarbonInterface
    {
        if (! $raw || ! preg_match('/^[0-9A-Fa-f]{16,22}$/', $raw)) {
            return null;
        }

        $h = strtoupper($raw);

        try {
            $year  = hexdec(substr($h, 0, 4));
            $month = hexdec(substr($h, 4, 2));
            $day   = hexdec(substr($h, 6, 2));
            $hour  = hexdec(substr($h, 8, 2));
            $min   = hexdec(substr($h, 10, 2));
            $sec   = hexdec(substr($h, 12, 2));
            // bytes 7-8 (chars 14-15): deciseconds — ignored
            $tz = 'UTC';
            if (strlen($h) >= 20) {
                // byte 9 (chars 16-17): tz direction: 0x2B='+', 0x2D='-'
                $tzSign = hexdec(substr($h, 16, 2)) === 0x2B ? '+' : '-';
                $tzHour = hexdec(substr($h, 18, 2));
                $tzMin  = strlen($h) >= 22 ? hexdec(substr($h, 20, 2)) : 0;
                $tz     = sprintf('%s%02d:%02d', $tzSign, $tzHour, $tzMin);
            }

            $dt = Carbon::createSafe($year, $month, $day, $hour, $min, $sec, $tz);

            return $dt instanceof Carbon ? $dt : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
