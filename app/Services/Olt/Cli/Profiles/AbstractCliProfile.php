<?php

namespace App\Services\Olt\Cli\Profiles;

use App\Models\Olt;
use App\Models\OltPort;
use App\Services\Olt\Cli\Contracts\CliProfile;
use Illuminate\Support\Collection;

abstract class AbstractCliProfile implements CliProfile
{
    abstract protected function vendorKey(): string;

    protected function cfg(string $key, mixed $default = null): mixed
    {
        return config("olt.cli.vendors.{$this->vendorKey()}.{$key}", $default);
    }

    public function prepCommands(Olt $olt): array
    {
        return array_values(array_filter((array) $this->cfg('prep', [])));
    }

    public function transportOptions(Olt $olt): array
    {
        return array_filter([
            'char_delay' => $this->cfg('char_delay'),
            'command_timeout' => $this->cfg('command_timeout'),
            'login_timeout' => $this->cfg('login_timeout'),
            'prompt_regex' => $this->cfg('prompt_regex'),
        ], fn ($v) => $v !== null);
    }

    /**
     * Extract frame/slot/port ("0/1/0") from a port name such as "GPON 0/1/0",
     * "gpon_0/1/0" or "GPON0/1" (BDCOM/VSOL style → "0/1").
     */
    public static function portPath(?string $name): ?string
    {
        if (! $name) {
            return null;
        }
        if (preg_match('/(\d+)\s*\/\s*(\d+)\s*\/\s*(\d+)/', $name, $m)) {
            return "{$m[1]}/{$m[2]}/{$m[3]}";
        }
        if (preg_match('/(\d+)\s*\/\s*(\d+)/', $name, $m)) {
            return "{$m[1]}/{$m[2]}";
        }

        return null;
    }

    /** Build a lookup "f/s/p" → OltPort for quick matching of parsed rows. */
    protected function portsByPath(Collection $ports): array
    {
        $map = [];
        foreach ($ports as $port) {
            /** @var OltPort $port */
            $path = self::portPath($port->name);
            if ($path) {
                $map[$path] = $port;
            }
        }

        return $map;
    }

    /** Parse "-19.37" / "-19.37(dBm)" / "-" into a float dBm or null. */
    public static function dbm(?string $token): ?float
    {
        if ($token === null) {
            return null;
        }
        $token = trim($token);
        if ($token === '' || $token === '-' || $token === '--' || strcasecmp($token, 'N/A') === 0) {
            return null;
        }
        if (! preg_match('/^(-?\d+(?:\.\d+)?)/', $token, $m)) {
            return null;
        }
        $v = (float) $m[1];

        return abs($v) > 60 ? null : round($v, 2);
    }

    /** Normalise any MAC notation to AA:BB:CC:DD:EE:FF. */
    public static function mac(string $raw): ?string
    {
        $hex = preg_replace('/[^0-9A-Fa-f]/', '', $raw);
        if (strlen($hex) !== 12) {
            return null;
        }
        $mac = strtoupper(implode(':', str_split($hex, 2)));

        return $mac === '00:00:00:00:00:00' || $mac === 'FF:FF:FF:FF:FF:FF' ? null : $mac;
    }

    protected function fill(string $template, array $vars): string
    {
        foreach ($vars as $k => $v) {
            $template = str_replace('{'.$k.'}', (string) $v, $template);
        }

        return $template;
    }
}
