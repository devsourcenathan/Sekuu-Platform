<?php

declare(strict_types=1);

namespace Modules\Identity\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ActionToken extends Model
{
    use HasUuids;

    public const PASSWORD_RESET = 'password_reset';

    public const EMAIL_VERIFICATION = 'email_verification';

    protected $fillable = [
        'user_id',
        'type',
        'token_hash',
        'expires_at',
    ];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function hash(string $plainToken): string
    {
        return hash('sha256', $plainToken);
    }

    public function isUsable(): bool
    {
        return $this->consumed_at === null && $this->expires_at->isFuture();
    }
}
