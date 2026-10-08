<?php

namespace App\Services\Olt\Cli;

/**
 * Parsed outcome of one CLI enrichment pass over an OLT.
 *
 * `rows` is keyed "<olt_port_id>:<ont_id>" and holds the values a profile
 * managed to extract for that ONU: rx_power, tx_power, olt_rx_power (dBm),
 * macs (string[]), serial, description, distance. Missing keys mean "not
 * provided by the CLI output" and are left untouched in the database.
 */
class CliEnrichResult
{
    /** @var array<string, array<string, mixed>> */
    public array $rows = [];

    /** @var string[] human notes: which commands ran, parse warnings, … */
    public array $notes = [];

    /** @var array<int, array{command: string, output: string, duration_ms?: int, error?: ?string}> */
    public array $outputs = [];

    /** Transcript of the login / prep phase, for troubleshooting prompts. */
    public ?string $loginLog = null;

    public static function key(int $portId, int $ontId): string
    {
        return "{$portId}:{$ontId}";
    }

    /** Merge values into a row without clobbering already-known values with nulls. */
    public function merge(int $portId, int $ontId, array $values): void
    {
        $key = self::key($portId, $ontId);
        $row = $this->rows[$key] ?? ['port_id' => $portId, 'ont_id' => $ontId];

        foreach ($values as $k => $v) {
            if ($k === 'macs') {
                $row['macs'] = array_values(array_unique(array_merge($row['macs'] ?? [], (array) $v)));
            } elseif ($v !== null && $v !== '') {
                $row[$k] = $v;
            }
        }

        $this->rows[$key] = $row;
    }

    public function note(string $note): void
    {
        $this->notes[] = $note;
    }

    /** @return array{optical: int, mac: int} how many ONUs received each kind of data */
    public function counts(): array
    {
        $optical = 0;
        $mac = 0;
        foreach ($this->rows as $row) {
            if (isset($row['rx_power']) || isset($row['tx_power']) || isset($row['olt_rx_power'])) {
                $optical++;
            }
            if (! empty($row['macs'])) {
                $mac++;
            }
        }

        return ['optical' => $optical, 'mac' => $mac];
    }
}
