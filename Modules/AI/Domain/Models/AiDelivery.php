<?php

declare(strict_types=1);

namespace Modules\AI\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Une tentative de livraison, et ce qu'elle est devenue.
 */
final class AiDelivery extends Model
{
    use HasUuids;

    public const PENDING = 'pending';

    public const DELIVERED = 'delivered';

    /** Tous les réessais consommés. La ligne reste, et reste visible. */
    public const EXHAUSTED = 'exhausted';

    protected $fillable = [
        'ai_endpoint_id', 'event_id', 'event_type', 'generation_id',
        'payload', 'status', 'attempts', 'last_status_code', 'last_error', 'delivered_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'attempts' => 'integer',
            'last_status_code' => 'integer',
            'delivered_at' => 'datetime',
        ];
    }

    public function endpoint(): BelongsTo
    {
        return $this->belongsTo(AiEndpoint::class, 'ai_endpoint_id');
    }
}
