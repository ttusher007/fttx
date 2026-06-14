<?php

namespace App\Enums;

enum SnmpVersion: string
{
    case V1 = 'v1';
    case V2c = 'v2c';
    case V3 = 'v3';

    public function label(): string
    {
        return match ($this) {
            self::V1 => 'SNMP v1',
            self::V2c => 'SNMP v2c',
            self::V3 => 'SNMP v3',
        };
    }
}
