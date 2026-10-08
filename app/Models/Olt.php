<?php

namespace App\Models;

use App\Enums\OltStatus;
use App\Enums\PonType;
use App\Enums\SnmpVersion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Olt extends Model
{
    protected $fillable = [
        'name', 'ip_address', 'vendor', 'model', 'location',
        'pon_type', 'pon_type_auto_detected',
        'snmp_version', 'snmp_port', 'snmp_community',
        'snmp_sec_name', 'snmp_auth_protocol', 'snmp_auth_password',
        'snmp_priv_protocol', 'snmp_priv_password',
        'ssh_username', 'ssh_port', 'ssh_password',
        'cli_enabled', 'cli_protocol', 'cli_interval',
        'status', 'live_fetch', 'is_simulated', 'sync_interval',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => OltStatus::class,
            'snmp_version' => SnmpVersion::class,
            'pon_type' => PonType::class,
            'pon_type_auto_detected' => 'boolean',
            'live_fetch' => 'boolean',
            'is_simulated' => 'boolean',
            'cli_enabled' => 'boolean',
            // Credentials are encrypted at rest.
            'snmp_community' => 'encrypted',
            'snmp_auth_password' => 'encrypted',
            'snmp_priv_password' => 'encrypted',
            'ssh_password' => 'encrypted',
            'last_synced_at' => 'datetime',
            'cli_last_synced_at' => 'datetime',
        ];
    }

    protected $hidden = [
        'snmp_community', 'snmp_auth_password', 'snmp_priv_password', 'ssh_password',
    ];

    public function ports(): HasMany
    {
        return $this->hasMany(OltPort::class);
    }

    public function onus(): HasMany
    {
        return $this->hasMany(Onu::class);
    }

    public function syncLogs(): HasMany
    {
        return $this->hasMany(SyncLog::class);
    }

    public function diagnosticRuns(): HasMany
    {
        return $this->hasMany(DiagnosticRun::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function effectiveInterval(): int
    {
        return $this->sync_interval ?: (int) config('olt.sync.default_interval', 15);
    }

    public function isDueForSync(): bool
    {
        if (! $this->live_fetch || $this->status !== OltStatus::Active) {
            return false;
        }

        if (! $this->last_synced_at) {
            return true;
        }

        return $this->last_synced_at->addMinutes($this->effectiveInterval())->isPast();
    }

    /** Minutes between CLI (SSH/Telnet) enrichment runs. */
    public function effectiveCliInterval(): int
    {
        return $this->cli_interval ?: (int) config('olt.cli.default_interval', 60);
    }

    /** Whether the OLT has CLI credentials and CLI enrichment switched on. */
    public function cliConfigured(): bool
    {
        return (bool) $this->cli_enabled
            && ! $this->shouldSimulate()
            && filled($this->ssh_username)
            && filled($this->ssh_password);
    }

    public function isDueForCliSync(): bool
    {
        if (! $this->cliConfigured() || ! $this->live_fetch || $this->status !== OltStatus::Active) {
            return false;
        }

        if (! $this->cli_last_synced_at) {
            return true;
        }

        return $this->cli_last_synced_at->addMinutes($this->effectiveCliInterval())->isPast();
    }

    /** TCP port for the CLI session (ssh_port for SSH, 23 for Telnet unless overridden). */
    public function cliPort(): int
    {
        if ($this->cli_protocol === 'telnet') {
            return (int) ($this->ssh_port && (int) $this->ssh_port !== 22 ? $this->ssh_port : 23);
        }

        return (int) ($this->ssh_port ?: 22);
    }

    public function shouldSimulate(): bool
    {
        return $this->is_simulated || (bool) config('olt.simulate', false);
    }

    public function scopeLive($query)
    {
        return $query->where('live_fetch', true)->where('status', OltStatus::Active->value);
    }
}
