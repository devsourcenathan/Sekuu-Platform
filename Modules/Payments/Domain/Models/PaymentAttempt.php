<?php

declare(strict_types=1);

namespace Modules\Billing\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Billing\Domain\AttemptStatus;
use Modules\Billing\Domain\Money;

/**
 * Ce qu'on a tenté, et chez quel agrégateur.
 *
 * @see docs/04-decisions/adr-0008-payment-aggregators-failover.md
 */
final class PaymentAttempt extends Model
{
    use HasUuids;

    protected $fillable = [
        'payment_intent_id', 'provider', 'priority', 'merchant_reference',
        'provider_ref', 'status', 'customer_prompted', 'gross_amount',
        'fee_amount', 'net_amount', 'failure_code', 'failure_reason',
        'raw_status', 'last_polled_at', 'poll_count', 'started_at', 'settled_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => AttemptStatus::class,
            'customer_prompted' => 'boolean',
            'priority' => 'integer',
            'poll_count' => 'integer',
            'gross_amount' => 'integer',
            'fee_amount' => 'integer',
            'net_amount' => 'integer',
            'last_polled_at' => 'immutable_datetime',
            'started_at' => 'immutable_datetime',
            'settled_at' => 'immutable_datetime',
        ];
    }

    public function intent(): BelongsTo
    {
        return $this->belongsTo(PaymentIntent::class, 'payment_intent_id');
    }

    public function grossMoney(): ?Money
    {
        return $this->gross_amount === null
            ? null
            : Money::of($this->gross_amount, $this->intent->currency);
    }

    /**
     * Peut-on essayer l'agrégateur suivant après cette tentative ?
     *
     * Deux conditions, et non une : le statut doit l'autoriser **et** l'invite
     * ne doit jamais être partie. La redondance est délibérée — c'est la
     * dernière barrière avant un double débit.
     */
    public function allowsFailover(): bool
    {
        return $this->status->allowsFailover() && ! $this->customer_prompted;
    }
}
