<?php

namespace App\Models;

use App\Enums\OnuStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Onu extends Model
{
    protected $fillable = [
        'olt_id', 'olt_port_id', 'onu_index', 'serial_number', 'mac_address',
        'name', 'description', 'status', 'rx_power', 'tx_power', 'distance',
        'online_since', 'last_seen_at', 'last_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => OnuStatus::class,
            'rx_power' => 'decimal:2',
            'tx_power' => 'decimal:2',
            'distance' => 'decimal:2',
            'online_since' => 'datetime',
            'last_seen_at' => 'datetime',
            'last_synced_at' => 'datetime',
        ];
    }

    public function olt(): BelongsTo
    {
        return $this->belongsTo(Olt::class);
    }

    public function port(): BelongsTo
    {
        return $this->belongsTo(OltPort::class, 'olt_port_id');
    }

    /**
     * Optical Rx signal quality bucket, used for UI coloring.
     */
    public function signalQuality(): string
    {
        $rx = $this->rx_power;

        if ($rx === null) {
            return 'unknown';
        }

        return match (true) {
            $rx >= -25 && $rx <= -8 => 'good',   // healthy GPON window (-8 to -25 dBm)
            $rx > -8 || $rx < -28 => 'critical', // too strong or too weak
            default => 'warning',                 // marginal (-25 to -28 dBm)
        };
    }

    public function scopeSearch($query, ?string $term)
    {
        if (! $term) {
            return $query;
        }

        $term = trim($term);

        return $query->where(function ($q) use ($term) {
            $q->where('serial_number', 'like', "%{$term}%")
                ->orWhere('mac_address', 'like', "%{$term}%")
                ->orWhere('name', 'like', "%{$term}%")
                ->orWhere('description', 'like', "%{$term}%");
        });
    }
}
