<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OltPort extends Model
{
    protected $fillable = [
        'olt_id', 'port_index', 'name', 'admin_status', 'oper_status',
        'onu_count', 'onu_online_count',
    ];

    public function olt(): BelongsTo
    {
        return $this->belongsTo(Olt::class);
    }

    public function onus(): HasMany
    {
        return $this->hasMany(Onu::class);
    }
}
