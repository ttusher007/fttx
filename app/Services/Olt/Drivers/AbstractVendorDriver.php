<?php

namespace App\Services\Olt\Drivers;

use App\Enums\OnuStatus;
use App\Models\Olt;
use App\Services\Olt\Contracts\VendorDriver;
use App\Services\Olt\Data\OnuInfo;
use App\Services\Olt\Data\PortInfo;
use App\Services\Olt\Data\SystemInfo;
use App\Services\Olt\Simulator\OltSimulator;
use App\Services\Snmp\BridgeFdbResolver;
use App\Services\Snmp\SnmpClient;
use Carbon\CarbonInterface;

/**
 * Shared SNMP logic for all vendor drivers. System info and ports come from
 * standard RFC1213/IF-MIB OIDs (vendor independent). ONU enumeration is driven
 * by the per-vendor OID map in config/olt.php, with vendor-specific quirks
 * (status codes, power scaling) overridable by subclasses.
 */
abstract class AbstractVendorDriver implements VendorDriver
{
    /**
     * Every ONU column the config OID map may define. Walked in this order;
     * each is optional. See config/olt.php for the meaning of each key.
     */
    public const COLUMNS = [
        'serial', 'run_status', 'description', 'model',
        'rx_power', 'tx_power', 'olt_rx_power', 'distance',
        'mac', 'onu_mac', 'mac_count',
        'online_since', 'last_down_time', 'last_down_cause',
    ];

    /**
     * The OLT currently being polled. Set at the start of fetch calls so the
     * config resolver can apply the right pon_type (GPON/EPON) OID overrides.
     */
    protected ?Olt $activeOlt = null;

    /** PON port ifIndexes, cached for the duration of one fetchOnus(). */
    private ?array $ponPortCache = null;

    /**
     * Notes collected during the last fetchOnus() (which tables were empty,
     * fallbacks used, timeouts). Shown by the diagnostics "enrich" run and
     * logged on sync so empty columns are explainable.
     *
     * @var string[]
     */
    public array $notes = [];

    /** Config key under olt.vendors.* */
    abstract protected function vendorKey(): string;

    /**
     * Effective vendor config, with the OLT's pon_type override merged on top
     * of the vendor defaults. Keys under `pon_types.{type}` replace the matching
     * top-level keys (e.g. a gpon/epon-specific `oids` map or `power_divisor`).
     */
    protected function config(): array
    {
        $base = config("olt.vendors.{$this->vendorKey()}", []);

        $type = $this->activeOlt?->pon_type?->value;
        if ($type && isset($base['pon_types'][$type]) && is_array($base['pon_types'][$type])) {
            return array_replace($base, $base['pon_types'][$type]);
        }

        return $base;
    }

    protected function oids(): array
    {
        return $this->config()['oids'] ?? [];
    }

    protected function fallbackOids(): array
    {
        return $this->config()['fallback_oids'] ?? [];
    }

    protected function powerDivisor(): int
    {
        return (int) ($this->config()['power_divisor'] ?? 100);
    }

    protected function distanceMultiplier(): float
    {
        return (float) ($this->config()['distance_multiplier'] ?? 1);
    }

    protected function parseDistance(?string $raw): ?float
    {
        if ($raw === null || $raw === '' || ! is_numeric($raw)) {
            return null;
        }
        $val = (float) $raw;
        // -1 is a common "not available" sentinel (e.g. Huawei offline ONU).
        if ($val < 0 || $val >= 2147483647) {
            return null;
        }
        return round($val * $this->distanceMultiplier());
    }

    /** Relative uptime value (seconds/minutes since the ONU came up). */
    protected function parseOnlineSince(?string $raw): ?CarbonInterface
    {
        if ($raw === null || $raw === '' || ! is_numeric($raw) || (int) $raw <= 0) {
            return null;
        }
        $unit = $this->config()['uptime_unit'] ?? 'seconds';
        return $unit === 'minutes'
            ? now()->subMinutes((int) $raw)
            : now()->subSeconds((int) $raw);
    }

    /**
     * Absolute event timestamp (e.g. "last down time"). Vendors encode this
     * differently (Huawei: SNMP DateAndTime hex); the default knows none.
     */
    protected function parseEventTime(?string $raw): ?CarbonInterface
    {
        return null;
    }

    /** Human label for a vendor "last down cause" code; default keeps the raw value. */
    protected function mapDownCause(?string $raw): ?string
    {
        $raw = trim((string) $raw);

        return $raw === '' || $raw === '-1' ? null : $raw;
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
        $this->activeOlt = $olt;
        $this->notes = [];
        $this->ponPortCache = null;

        if ($olt->shouldSimulate()) {
            return app(OltSimulator::class)->onus($olt);
        }

        $oids = $this->oids();
        if (empty($oids['serial']) && empty($oids['run_status'])) {
            $this->notes[] = 'No vendor OID map configured — nothing to enumerate.';

            return []; // no vendor map → nothing to enumerate
        }

        $client = SnmpClient::forOlt($olt);

        $tables = $this->walkOnuTables($client, $oids);
        $ifNames = $this->fetchIfNames($client);
        $fdb = $this->resolveFdbMacs($client);

        $client->close();

        // Union of all ONU indexes we saw across identity tables.
        $indexes = array_unique(array_merge(
            array_keys($tables['serial'] ?? []),
            array_keys($tables['run_status'] ?? []),
            array_keys($tables['rx_power'] ?? []),
        ));

        $onus = [];
        foreach ($indexes as $index) {
            $index = (string) $index;
            $portIndex = $this->derivePortIndex($index);

            $onus[] = $this->buildOnu(
                onuIndex: $index,
                portIndex: $portIndex,
                name: $this->buildOnuName($index, $portIndex, $ifNames),
                status: $this->mapStatus($tables['run_status'][$index] ?? null),
                tables: $tables,
                key: $index,
            );
        }

        return $this->applyFdbMacs($onus, $fdb);
    }

    public function fetchOnu(Olt $olt, string $onuIndex): ?OnuInfo
    {
        $this->activeOlt = $olt;

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

    // ---- Shared ONU table plumbing ---------------------------------------

    /**
     * Walk every configured ONU column, applying per-column walk tuning and
     * fallback OIDs. Returns [columnKey => [index => raw value]].
     *
     * @return array<string, array<string, string>>
     */
    protected function walkOnuTables(SnmpClient $client, array $oids): array
    {
        $tables = [];
        $fallbacks = $this->fallbackOids();

        foreach (self::COLUMNS as $key) {
            if (empty($oids[$key])) {
                continue;
            }

            $rows = $this->walkColumn($client, $key, $oids[$key]);

            if (empty($rows) && ! empty($fallbacks[$key])) {
                $this->notes[] = "{$key}: primary table empty".($client->lastError ? " ({$client->lastError})" : '').' — trying fallback OID';
                $rows = $this->walkColumn($client, $key, $fallbacks[$key]);
            }

            if (empty($rows)) {
                $this->notes[] = "{$key}: 0 rows".($client->lastError ? " ({$client->lastError})" : '');
            }

            $tables[$key] = $rows;
        }

        return $tables;
    }

    /**
     * Walk one ONU column honouring `walk.<key>` tuning from config:
     * per-PON-port walks and/or a slower, smaller-window SNMP session.
     *
     * @return array<string, string>
     */
    protected function walkColumn(SnmpClient $client, string $key, string $oid): array
    {
        $opts = $this->config()['walk'][$key] ?? null;

        if (! is_array($opts) || empty($opts)) {
            return $client->walk($oid);
        }

        $walker = $client;
        $ownSession = false;
        if ($this->activeOlt && (isset($opts['timeout']) || isset($opts['max_repetitions']) || isset($opts['retries']))) {
            $walker = SnmpClient::forOlt(
                $this->activeOlt,
                isset($opts['timeout']) ? (int) $opts['timeout'] : null,
                isset($opts['retries']) ? (int) $opts['retries'] : null,
                isset($opts['max_repetitions']) ? (int) $opts['max_repetitions'] : null,
            );
            $ownSession = true;
        }

        try {
            if (empty($opts['per_port'])) {
                $rows = $walker->walk($oid);
                $client->lastError = $walker->lastError;

                return $rows;
            }

            $rows = [];
            $errors = 0;
            foreach ($this->ponPortIndexes($client) as $portIndex) {
                $chunk = $walker->walk("{$oid}.{$portIndex}");
                if (empty($chunk) && $walker->lastError) {
                    $errors++;
                }
                foreach ($chunk as $sub => $value) {
                    $rows["{$portIndex}.{$sub}"] = $value;
                }
            }
            if ($errors) {
                $client->lastError = "{$errors} per-port walk(s) failed: ".$walker->lastError;
            }

            return $rows;
        } finally {
            if ($ownSession) {
                $walker->close();
            }
        }
    }

    /**
     * ifIndexes of the PON ports (used for per-port column walks). Default:
     * IF-MIB ifDescr filtered through looksLikePonPort().
     *
     * @return string[]
     */
    protected function ponPortIndexes(SnmpClient $client): array
    {
        if ($this->ponPortCache !== null) {
            return $this->ponPortCache;
        }

        $out = [];
        foreach ($client->walk(config('olt.standard.ifDescr')) as $index => $name) {
            if ($this->looksLikePonPort($name)) {
                $out[] = (string) $index;
            }
        }

        return $this->ponPortCache = $out;
    }

    /**
     * Assemble an OnuInfo from the walked tables for one index key.
     *
     * @param  array<string, array<string, string>>  $tables
     */
    protected function buildOnu(string $onuIndex, ?string $portIndex, ?string $name, OnuStatus $status, array $tables, string $key): OnuInfo
    {
        $col = fn (string $c) => $tables[$c][$key] ?? null;

        $mac = $this->normaliseMac($col('mac'));

        return new OnuInfo(
            onuIndex: $onuIndex,
            portIndex: $portIndex,
            serialNumber: $this->normaliseSerial($col('serial')),
            macAddress: $mac,
            name: $name,
            description: $this->cleanText($col('description')),
            status: $status,
            rxPower: $this->parsePower($col('rx_power')),
            txPower: $this->parsePower($col('tx_power')),
            distance: $this->parseDistance($col('distance')),
            onlineSince: $this->parseOnlineSince($col('online_since')),
            oltRxPower: $this->parsePower($col('olt_rx_power')),
            onuMac: $this->normaliseMac($col('onu_mac')),
            macCount: is_numeric($col('mac_count')) && (int) $col('mac_count') >= 0 ? (int) $col('mac_count') : null,
            macSource: $mac ? 'snmp' : null,
            model: $this->cleanText($col('model')),
            lastDownAt: $this->parseEventTime($col('last_down_time')),
            lastDownCause: $this->mapDownCause($col('last_down_cause')),
        );
    }

    /**
     * Learned customer MACs from the bridge FDB, when the vendor config asks
     * for it. Returns [ifIndex => [mac, ...]] or [].
     */
    protected function resolveFdbMacs(SnmpClient $client): array
    {
        $strategies = $this->config()['mac_fdb'] ?? [];
        if (empty($strategies)) {
            return [];
        }

        $resolver = new BridgeFdbResolver;
        $map = $resolver->resolve($client, $strategies);
        $this->notes = array_merge($this->notes, array_map(fn ($n) => "fdb {$n}", $resolver->notes));

        return $map;
    }

    /**
     * Attach FDB-learned MACs to ONUs. The ONU's own MAC / MAC-style serial is
     * excluded so we report the customer's router, not the ONU itself. ONUs
     * that already carry a MAC from a vendor table keep it.
     *
     * @param  OnuInfo[]  $onus
     * @param  array<string, string[]>  $fdb
     * @return OnuInfo[]
     */
    protected function applyFdbMacs(array $onus, array $fdb): array
    {
        if (empty($fdb)) {
            return $onus;
        }

        return array_map(function (OnuInfo $onu) use ($fdb) {
            if ($onu->macAddress) {
                return $onu;
            }
            $ifIndex = $this->fdbInterfaceIndex($onu);
            $macs = $ifIndex !== null ? ($fdb[$ifIndex] ?? []) : [];
            if (empty($macs)) {
                return $onu;
            }

            $own = array_filter([$onu->onuMac, $this->normaliseMac($onu->serialNumber)]);
            $macs = array_values(array_filter($macs, fn ($m) => ! in_array($m, $own, true)));
            if (empty($macs)) {
                return $onu;
            }

            return $onu->with([
                'macAddress' => $macs[0],
                'macCount' => $onu->macCount ?? count($macs),
                'macSource' => 'fdb',
            ]);
        }, $onus);
    }

    /** The IF-MIB ifIndex of an ONU, for the FDB join. Default: the onu index itself. */
    protected function fdbInterfaceIndex(OnuInfo $onu): ?string
    {
        return $onu->onuIndex;
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
        if ($raw === null) {
            return null;
        }
        $raw = trim($raw);
        if ($raw === '' || ! is_numeric($raw)) {
            return null;
        }

        $value = (float) $raw / $this->powerDivisor();

        // Treat obviously-invalid sentinels (e.g. 2147483647, 0 on VSOL offline) as "no reading".
        if (abs($value) > 60) {
            return null;
        }

        return round($value, 2);
    }

    protected function normaliseMac(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }
        $raw = trim($raw);
        if ($raw === '' || $raw === '-1' || strtoupper($raw) === 'N/A') {
            return null;
        }

        // Hex blob / any notation → AA:BB:CC:DD:EE:FF.
        $hex = preg_replace('/[^0-9A-Fa-f]/', '', $raw);
        if (strlen($hex) === 12 && (strlen($raw) <= 17 || ! ctype_print($raw) || preg_match('/^[0-9A-Fa-f]{12}$/', $raw))) {
            $mac = strtoupper(implode(':', str_split($hex, 2)));

            return $mac === '00:00:00:00:00:00' ? null : $mac;
        }

        // Already colon-formatted?
        if (str_contains($raw, ':') && strlen($hex) === 12) {
            return strtoupper($raw);
        }

        return null;
    }

    protected function normaliseSerial(?string $raw): ?string
    {
        $raw = trim((string) $raw);

        return $raw === '' || $raw === '-1' ? null : strtoupper($raw);
    }

    /** Drop empty / placeholder strings so they don't overwrite real values. */
    protected function cleanText(?string $raw): ?string
    {
        $raw = trim((string) $raw);

        if ($raw === '' || in_array(strtoupper($raw), ['N/A', 'NULL', '-', '-1', 'NA', 'NONE'], true)) {
            return null;
        }

        return mb_substr($raw, 0, 190);
    }

    /**
     * Optionally fetch a portIfIndex → name map during the ONU walk.
     * Returns [] by default (no extra SNMP round trip); override when needed.
     */
    protected function fetchIfNames(SnmpClient $client): array
    {
        return [];
    }

    /**
     * Optionally build an ONU sub-interface name (e.g. "GPON 0/0/0:2").
     * Returns null by default (the UI falls back to onu_index).
     */
    protected function buildOnuName(string $onuIndex, ?string $portIndex, array $ifNames): ?string
    {
        return null;
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
        if (! $name || str_contains($name, ':')) {
            // Skip ONU sub-interfaces such as GPON0/8:5 or EPON0/1:17.
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
