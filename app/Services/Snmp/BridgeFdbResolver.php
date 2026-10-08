<?php

namespace App\Services\Snmp;

/**
 * Resolves customer (router/CPE) MAC addresses learned behind ONUs from the
 * OLT's bridge forwarding database (FDB) over SNMP.
 *
 * Works on OLTs that expose each ONU as an IF-MIB interface (BDCOM, VSOL, ...):
 * the FDB maps MAC → bridge port → ifIndex, and that ifIndex is the ONU's
 * onu_index. Three table layouts are supported and tried in the configured
 * order until one returns rows:
 *
 *   bdcom_fdb  NMS-MAC-MIB fdbReadByPortTable  1.3.6.1.4.1.3320.152.1.1.3
 *              index = ifIndex.vlan.m1.m2.m3.m4.m5.m6 (MAC is in the index)
 *   q_bridge   Q-BRIDGE-MIB dot1qTpFdbPort      1.3.6.1.2.1.17.7.1.2.2.1.2
 *              index = vlan.m1..m6, value = bridge port
 *   d_bridge   BRIDGE-MIB dot1dTpFdbPort        1.3.6.1.2.1.17.4.3.1.2
 *              index = m1..m6, value = bridge port
 *
 * Bridge ports are translated to ifIndexes through dot1dBasePortIfIndex
 * (1.3.6.1.2.1.17.1.4.1.2); when that table is absent the port number is
 * assumed to equal the ifIndex.
 */
class BridgeFdbResolver
{
    public const OID_BDCOM_FDB = '1.3.6.1.4.1.3320.152.1.1.3';

    public const OID_Q_BRIDGE_FDB_PORT = '1.3.6.1.2.1.17.7.1.2.2.1.2';

    public const OID_D_BRIDGE_FDB_PORT = '1.3.6.1.2.1.17.4.3.1.2';

    public const OID_BASE_PORT_IFINDEX = '1.3.6.1.2.1.17.1.4.1.2';

    /** @var string[] */
    public array $notes = [];

    /**
     * @param  string[]  $strategies  subset of bdcom_fdb|q_bridge|d_bridge, in order
     * @return array<string, string[]> ifIndex => list of MACs ("AA:BB:CC:DD:EE:FF")
     */
    public function resolve(SnmpClient $client, array $strategies): array
    {
        $this->notes = [];

        foreach ($strategies as $strategy) {
            $result = match ($strategy) {
                'bdcom_fdb' => $this->viaBdcomFdb($client),
                'q_bridge' => $this->viaBridgePort($client, self::OID_Q_BRIDGE_FDB_PORT, true),
                'd_bridge' => $this->viaBridgePort($client, self::OID_D_BRIDGE_FDB_PORT, false),
                default => [],
            };

            if (! empty($result)) {
                $this->notes[] = "{$strategy}: ".count($result).' interfaces with learned MACs';

                return $result;
            }

            $this->notes[] = "{$strategy}: no rows".($client->lastError ? " ({$client->lastError})" : '');
        }

        return [];
    }

    /** @return array<string, string[]> */
    private function viaBdcomFdb(SnmpClient $client): array
    {
        $rows = $client->walk(self::OID_BDCOM_FDB);
        $out = [];

        foreach ($rows as $index => $value) {
            // ifIndex.vlan.m1.m2.m3.m4.m5.m6
            $parts = explode('.', $index);
            if (count($parts) < 8) {
                continue;
            }
            $ifIndex = $parts[0];
            $mac = self::macFromIndexParts(array_slice($parts, 2, 6)) ?? self::normaliseMac($value);
            if ($mac) {
                $out[$ifIndex][] = $mac;
            }
        }

        return self::dedupe($out);
    }

    /** @return array<string, string[]> */
    private function viaBridgePort(SnmpClient $client, string $fdbOid, bool $vlanIndexed): array
    {
        $rows = $client->walk($fdbOid);
        if (empty($rows)) {
            return [];
        }

        $portToIf = $client->walk(self::OID_BASE_PORT_IFINDEX);

        $out = [];
        foreach ($rows as $index => $port) {
            $parts = explode('.', $index);
            if ($vlanIndexed) {
                array_shift($parts);
            }
            if (count($parts) !== 6) {
                continue;
            }
            $mac = self::macFromIndexParts($parts);
            $port = (string) (int) $port;
            if (! $mac || $port === '0') {
                continue;
            }
            $ifIndex = $portToIf[$port] ?? $port;
            $out[(string) $ifIndex][] = $mac;
        }

        return self::dedupe($out);
    }

    /** @param  string[]  $parts  six decimal octets */
    public static function macFromIndexParts(array $parts): ?string
    {
        if (count($parts) !== 6) {
            return null;
        }
        $hex = [];
        foreach ($parts as $p) {
            if (! is_numeric($p) || (int) $p < 0 || (int) $p > 255) {
                return null;
            }
            $hex[] = sprintf('%02X', (int) $p);
        }

        return implode(':', $hex);
    }

    public static function normaliseMac(?string $raw): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        $hex = preg_replace('/[^0-9A-Fa-f]/', '', $raw);
        if (strlen($hex) !== 12) {
            return null;
        }

        return strtoupper(implode(':', str_split($hex, 2)));
    }

    /** @param  array<string, string[]>  $map */
    private static function dedupe(array $map): array
    {
        foreach ($map as $k => $macs) {
            $map[$k] = array_values(array_unique($macs));
        }

        return $map;
    }
}
