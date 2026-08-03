<?php

declare(strict_types=1);

namespace Modules\Billing\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Callback reçu d'un agrégateur, corps brut conservé.
 *
 * Quand un paiement est contesté, c'est la seule pièce qui dit ce que
 * l'agrégateur a réellement envoyé — pas ce que le code en a compris.
 */
final class ProviderEvent extends Model
{
    use HasUuids;

    protected $fillable = [
        'provider', 'provider_event_id', 'payment_attempt_id', 'payload',
        'signature_valid', 'received_at', 'processed_at', 'error',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'signature_valid' => 'boolean',
            'received_at' => 'immutable_datetime',
            'processed_at' => 'immutable_datetime',
        ];
    }
}
