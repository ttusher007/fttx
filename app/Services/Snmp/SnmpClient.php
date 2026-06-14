<?php

namespace App\Services\Snmp;

use App\Enums\SnmpVersion;
use App\Models\Olt;
use SNMP;
use Throwable;

/**
 * Thin, safe wrapper around PHP's ext-snmp. All OIDs are numeric and all
 * values are returned plain (no "Type: value" prefixes), so callers never have
 * to deal with MIB translation. A single instance reuses one SNMP session for
 * the lifetime of a sync, which is what makes bulk-walking 200+ OLTs fast.
 */
class SnmpClient
{
    private SNMP $session;

    public function __construct(
        private readonly string $host,
        private readonly int $port,
        SnmpVersion $version,
        string $community,
        int $timeout,
        int $retries,
        private readonly int $maxRepetitions = 20,
        array $v3 = [],
    ) {
        $versionConst = match ($version) {
            SnmpVersion::V1 => SNMP::VERSION_1,
            SnmpVersion::V2c => SNMP::VERSION_2c,
            SnmpVersion::V3 => SNMP::VERSION_3,
        };

        // For v3 the "community" slot carries the security name.
        $secName = $version === SnmpVersion::V3 ? ($v3['sec_name'] ?? '') : $community;

        $this->session = new SNMP($versionConst, "{$host}:{$port}", $secName, $timeout, $retries);
        $this->session->oid_output_format = SNMP_OID_OUTPUT_NUMERIC; // = 4; class constant missing in some builds
        $this->session->valueretrieval = SNMP_VALUE_PLAIN;          // = 1; ditto
        $this->session->enum_print = false;
        $this->session->exceptions_enabled = SNMP::ERRNO_ANY;

        if ($version === SnmpVersion::V3) {
            $secLevel = ($v3['auth_password'] ?? null)
                ? (($v3['priv_password'] ?? null) ? 'authPriv' : 'authNoPriv')
                : 'noAuthNoPriv';

            $this->session->setSecurity(
                $secLevel,
                $v3['auth_protocol'] ?? 'SHA',
                $v3['auth_password'] ?? '',
                $v3['priv_protocol'] ?? 'AES',
                $v3['priv_password'] ?? '',
            );
        }
    }

    /**
     * Build a client straight from an OLT model + app config.
     */
    public static function forOlt(Olt $olt): self
    {
        $cfg = config('olt.snmp');

        return new self(
            host: $olt->ip_address,
            port: (int) $olt->snmp_port,
            version: $olt->snmp_version,
            community: (string) $olt->snmp_community,
            timeout: (int) $cfg['timeout'],
            retries: (int) $cfg['retries'],
            maxRepetitions: (int) $cfg['max_repetitions'],
            v3: [
                'sec_name' => $olt->snmp_sec_name,
                'auth_protocol' => $olt->snmp_auth_protocol,
                'auth_password' => $olt->snmp_auth_password,
                'priv_protocol' => $olt->snmp_priv_protocol,
                'priv_password' => $olt->snmp_priv_password,
            ],
        );
    }

    /**
     * GET a single scalar OID. Returns null on noSuchObject / error.
     */
    public function get(string $oid): ?string
    {
        try {
            $value = $this->session->get($oid);

            return $value === false ? null : $this->cleanValue($value);
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * Walk a subtree and return a map of [trailing-index => value].
     *
     * The trailing index is the portion of each returned OID after the base
     * OID — e.g. walking ...43.1.3 with a leaf ...43.1.3.4194304512.0 yields
     * index "4194304512.0". GETBULK is used on v2c/v3 for speed.
     *
     * @return array<string, string>
     */
    public function walk(string $baseOid): array
    {
        $base = ltrim($baseOid, '.');

        try {
            // walk() uses GETBULK automatically for v2c/v3 with max_oids.
            $raw = $this->session->walk($baseOid, false, $this->maxRepetitions);
        } catch (Throwable $e) {
            return [];
        }

        if ($raw === false) {
            return [];
        }

        $out = [];
        foreach ($raw as $oid => $value) {
            $oid = ltrim($oid, '.');
            $index = str_starts_with($oid, $base.'.')
                ? substr($oid, strlen($base) + 1)
                : $oid;
            $out[$index] = $this->cleanValue($value);
        }

        return $out;
    }

    /**
     * Cheap reachability probe (sysUpTime). Returns true if the device answers.
     */
    public function ping(): bool
    {
        return $this->get('1.3.6.1.2.1.1.3.0') !== null;
    }

    public function close(): void
    {
        try {
            $this->session->close();
        } catch (Throwable) {
            // already closed
        }
    }

    /**
     * Normalise a raw SNMP value: strip surrounding quotes, trim, and convert
     * true binary blobs (MACs, serials) to uppercase hex. Textual OCTET STRINGs
     * such as sysDescr often include trailing nulls — those stay readable text.
     */
    private function cleanValue(string $value): string
    {
        $value = trim($value, " \t\n\r\0\x0B\"");

        if ($value === '') {
            return '';
        }

        if (ctype_print($value)) {
            return $value;
        }

        // sysDescr/sysName frequently include null or control bytes on BDCOM/Huawei gear.
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value) ?? $value;
        $text = trim($text);

        if ($text !== '' && ctype_print($text)) {
            return $text;
        }

        // Some builds already return an uppercase hex encoding of ASCII text.
        if (preg_match('/^[0-9A-Fa-f]+$/', $value) && strlen($value) % 2 === 0) {
            $decoded = hex2bin($value);

            if ($decoded !== false) {
                $fromHex = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $decoded) ?? $decoded;
                $fromHex = trim($fromHex);

                if ($fromHex !== '' && ctype_print($fromHex)) {
                    return $fromHex;
                }
            }
        }

        return strtoupper(bin2hex($value));
    }
}
