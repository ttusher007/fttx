<?php

namespace App\Enums;

enum SyncStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Success = 'success';
    case Partial = 'partial';
    case Failed = 'failed';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'slate',
            self::Running => 'blue',
            self::Success => 'emerald',
            self::Partial => 'amber',
            self::Failed => 'red',
        };
    }
}
