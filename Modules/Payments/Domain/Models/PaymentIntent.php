<?php

declare(strict_types=1);

namespace Modules\Billing\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Billing\Domain\Money;

/**
 * Ce que le client veut payer — à distinguer de ce qu'on a tenté, et chez qui.
 *
 * Une facture n'est payée qu'une fois, même si trois agrégateurs ont été
 * sollicités.
 *
 * @see docs/04-decisions/adr-0008-payment-aggregators-failover.md
 */
final class PaymentIntent extends Model
{
    use HasUuids;

    public const PENDING = 'pending';

    public const PROCESSING = 'processing';

    public const SUCCEEDED = 'succeeded';

    public const FAILED = 'failed';

    /** **On ne sait pas** — ce qui n'est pas « cela a échoué ». */
    public const EXPIRED = 'expired';

    public const CANCELLED = 'cancelled';

    protected $fillable = [
        'organization_id', 'invoice_id', 'amount', 'currency', 'method',
        'operator', 'msisdn', 'status', 'failure_code', 'failure_reason',
        'idempotency_key', 'expires_at', 'initiated_by', 'request_id',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'expires_at' => 'immutable_datetime',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(PaymentAttempt::class)->orderBy('priority');
    }

    public function money(): Money
    {
        return Money::of($this->amount, $this->currency);
    }

    public function isSettled(): bool
    {
        return in_array($this->status, [self::SUCCEEDED, self::FAILED, self::EXPIRED, self::CANCELLED], true);
    }
}
