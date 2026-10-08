<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One diagnostics request (SNMP walk, CLI command batch, or CLI enrichment
 * dry-run) against an OLT, executed by RunDiagnosticJob. The full raw output
 * is stored so OID / parser tuning can be done from the browser.
 */
class DiagnosticRun extends Model
{
    public const KIND_SNMP = 'snmp';

    public const KIND_CLI = 'cli';

    public const KIND_ENRICH = 'enrich';

    protected $fillable = [
        'olt_id', 'user_id', 'kind', 'title', 'params', 'status', 'summary',
        'output', 'duration_ms', 'started_at', 'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'params' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function olt(): BelongsTo
    {
        return $this->belongsTo(Olt::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isFinished(): bool
    {
        return in_array($this->status, ['success', 'failed'], true);
    }
}
