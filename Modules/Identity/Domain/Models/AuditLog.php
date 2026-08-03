<?php

declare(strict_types=1);

namespace Modules\Identity\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * Entrée du journal d'audit.
 *
 * La table est **append-only** : un journal modifiable ne prouve rien.
 *
 * @see docs/02-standards/security.md
 */
final class AuditLog extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'organization_id',
        'action',
        'target_type',
        'target_id',
        'ip_address',
        'user_agent',
        'request_id',
        'payload',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // L'immuabilité est garantie ici plutôt que par convention : une
        // modification silencieuse du journal serait indétectable.
        self::updating(function (): never {
            throw new RuntimeException('Audit log entries are immutable.');
        });

        self::deleting(function (): never {
            throw new RuntimeException('Audit log entries cannot be deleted.');
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
