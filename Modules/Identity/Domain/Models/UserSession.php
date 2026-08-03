<?php

declare(strict_types=1);

namespace Modules\Identity\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un appareil connecté. Son identifiant est porté par le claim `sid` du JWT,
 * ce qui permet de révoquer un appareil précis.
 *
 * @see docs/02-standards/security.md
 */
final class UserSession extends Model
{
    use HasUuids;

    protected $table = 'user_sessions';

    protected $fillable = [
        'user_id',
        'device_name',
        'platform',
        'browser',
        'ip_address',
        'last_activity',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'last_activity' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function refreshTokens(): HasMany
    {
        return $this->hasMany(RefreshToken::class, 'session_id');
    }

    public function isUsable(): bool
    {
        return $this->revoked_at === null && $this->expires_at->isFuture();
    }

    public function revoke(): void
    {
        if ($this->revoked_at === null) {
            $this->forceFill(['revoked_at' => now()])->save();
        }

        $this->refreshTokens()->whereNull('revoked_at')->update(['revoked_at' => now()]);
    }
}
