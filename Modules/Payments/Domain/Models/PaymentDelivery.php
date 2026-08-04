<?php

declare(strict_types=1);

namespace Modules\Payments\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Une livraison sortante, persistée **avant** tout appel réseau.
 *
 * Une livraison qui n'aboutit jamais doit rester visible. Confiée à la seule
 * file, elle disparaîtrait avec la tâche qui la portait — et personne ne
 * saurait qu'un produit ignore un encaissement.
 *
 * @see docs/03-services/payments/07-external-api.md
 */
final class PaymentDelivery extends Model
{
    use HasUuids;

    public const PENDING = 'pending';

    public const DELIVERED = 'delivered';

    /**
     * Tous les réessais consommés.
     *
     * L'endpoint n'est **pas** désactivé pour autant : une panne de quelques
     * heures transformerait alors une interruption en silence permanent. C'est
     * la réconciliation qui rattrape, et elle existe pour ça.
     */
    public const EXHAUSTED = 'exhausted';

    protected $fillable = [
        'payment_endpoint_id', 'event_id', 'event_type', 'payment_intent_id',
        'payload', 'status', 'attempts', 'last_status_code', 'last_error',
        'delivered_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'attempts' => 'integer',
            'delivered_at' => 'datetime',
        ];
    }

    public function endpoint(): BelongsTo
    {
        return $this->belongsTo(PaymentEndpoint::class, 'payment_endpoint_id');
    }
}
