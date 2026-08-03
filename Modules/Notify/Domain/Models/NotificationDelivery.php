<?php

declare(strict_types=1);

namespace Modules\Notify\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class NotificationDelivery extends Model
{
    use HasUuids;

    public const PENDING = 'pending';

    public const ACCEPTED = 'accepted';

    public const REJECTED = 'rejected';

    public const FAILED = 'failed';

    protected $table = 'notification_deliveries';

    protected $fillable = [
        'notification_id',
        'provider',
        'attempt',
        'status',
        'provider_message_id',
        'error_code',
        'error_message',
        'cost_amount',
        'cost_currency',
        'sent_at',
    ];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime'];
    }

    public function notification(): BelongsTo
    {
        return $this->belongsTo(Notification::class);
    }
}
