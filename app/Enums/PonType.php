<?php

namespace App\Enums;

/**
 * The PON technology an OLT speaks. Used to pick the correct vendor OID map
 * (GPON and EPON expose ONUs through different SNMP tables) and shown in the UI.
 */
enum PonType: string
{
    case Gpon = 'gpon';
    case Epon = 'epon';

    public function label(): string
    {
        return match ($this) {
            self::Gpon => 'GPON',
            self::Epon => 'EPON',
        };
    }
}
