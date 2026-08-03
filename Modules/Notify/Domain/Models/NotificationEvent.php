<?php

declare(strict_types=1);

namespace Modules\Notify\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Ce que les fournisseurs rapportent après coup.
 *
 * Table **append-only** : un historique de livraison modifiable ne prouve rien.
 */
final class NotificationEvent extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    public const DELIVERED = 'delivered';

    public const BOUNCED = 'bounced';

    public const COMPLAINED = 'complained';

    public const REJECTED = 'rejected';

    protected $fillable = [
        'notification_id',
        'delivery_id',
        'type',
        'provider',
        'provider_event_id',
        'payload',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'occurred_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        self::updating(function (): never {
            throw new RuntimeException('Notification events are immutable.');
        });

        self::deleting(function (): never {
            throw new RuntimeException('Notification events cannot be deleted.');
        });
    }
}
