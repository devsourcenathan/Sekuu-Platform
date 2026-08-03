<?php

declare(strict_types=1);

namespace Modules\Notify\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Notification extends Model
{
    use HasUuids;

    public const QUEUED = 'queued';

    public const SENDING = 'sending';

    public const SENT = 'sent';

    public const DELIVERED = 'delivered';

    public const FAILED = 'failed';

    public const SUPPRESSED = 'suppressed';

    public const CANCELLED = 'cancelled';

    protected $fillable = [
        'organization_id',
        'user_id',
        'template_id',
        'template_key',
        'channel',
        'category',
        'locale',
        'recipient',
        'rendered_subject',
        'rendered_body',
        'payload',
        'status',
        'idempotency_key',
        'source_event_id',
        'request_id',
        'scheduled_for',
        'failed_reason',
    ];

    /**
     * Le corps rendu contient des liens à usage unique — réinitialisation,
     * invitation. Il ne doit jamais être sérialisé vers un client.
     */
    protected $hidden = ['rendered_body', 'rendered_subject'];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'scheduled_for' => 'datetime',
            'read_at' => 'datetime',
        ];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(NotificationTemplate::class, 'template_id');
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(NotificationDelivery::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(NotificationEvent::class);
    }

    public function isCancellable(): bool
    {
        return in_array($this->status, [self::QUEUED], true);
    }

    /**
     * Masque une destination pour la consultation : le support a besoin de
     * reconnaître un destinataire, pas de le lire en entier.
     */
    public function maskedRecipient(): string
    {
        $value = (string) $this->recipient;

        if (str_contains($value, '@')) {
            [$local, $domain] = explode('@', $value, 2);

            return mb_substr($local, 0, 1).str_repeat('*', max(1, mb_strlen($local) - 1)).'@'.$domain;
        }

        return str_repeat('*', max(0, mb_strlen($value) - 4)).mb_substr($value, -4);
    }
}
