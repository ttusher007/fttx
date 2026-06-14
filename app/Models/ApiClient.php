<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class ApiClient extends Model
{
    protected $fillable = [
        'name', 'key', 'secret_hash', 'abilities', 'rate_limit',
        'is_active', 'last_used_at', 'last_used_ip', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'abilities' => 'array',
            'is_active' => 'boolean',
            'last_used_at' => 'datetime',
        ];
    }

    protected $hidden = ['secret_hash'];

    /**
     * Create a new client and return [model, plainSecret]. The plain secret is
     * shown to the operator exactly once and never persisted in clear text.
     *
     * @return array{0: self, 1: string}
     */
    public static function issue(string $name, array $abilities, int $rateLimit = 120, ?int $createdBy = null): array
    {
        $plainSecret = 'sk_'.Str::random(48);

        $client = static::create([
            'name' => $name,
            'key' => 'ck_'.Str::lower(Str::random(24)),
            'secret_hash' => Hash::make($plainSecret),
            'abilities' => $abilities,
            'rate_limit' => $rateLimit,
            'is_active' => true,
            'created_by' => $createdBy,
        ]);

        return [$client, $plainSecret];
    }

    public function verifySecret(string $secret): bool
    {
        return Hash::check($secret, $this->secret_hash);
    }

    public function hasAbility(string $ability): bool
    {
        $abilities = $this->abilities ?? [];

        return in_array('*', $abilities, true) || in_array($ability, $abilities, true);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
