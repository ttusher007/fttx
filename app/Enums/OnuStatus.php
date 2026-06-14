<?php

namespace App\Enums;

enum OnuStatus: string
{
    case Online = 'online';
    case Offline = 'offline';
    case Losi = 'los';          // Loss of Signal
    case Dying = 'dying_gasp';  // Dying gasp / power loss
    case Unknown = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::Online => 'Online',
            self::Offline => 'Offline',
            self::Losi => 'LOS',
            self::Dying => 'Dying Gasp',
            self::Unknown => 'Unknown',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Online => 'emerald',
            self::Offline => 'zinc',
            self::Losi => 'red',
            self::Dying => 'orange',
            self::Unknown => 'slate',
        };
    }

    public function isHealthy(): bool
    {
        return $this === self::Online;
    }
}
