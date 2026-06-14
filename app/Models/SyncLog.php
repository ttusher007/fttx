<?php

namespace App\Models;

use App\Enums\SyncStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SyncLog extends Model
{
    protected $fillable = [
        'olt_id', 'onu_id', 'type', 'trigger', 'status', 'message',
        'stats', 'duration_ms', 'triggered_by', 'started_at', 'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => SyncStatus::class,
            'stats' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function olt(): BelongsTo
    {
        return $this->belongsTo(Olt::class);
    }

    public function onu(): BelongsTo
    {
        return $this->belongsTo(Onu::class);
    }

    public function triggeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by');
    }
}
