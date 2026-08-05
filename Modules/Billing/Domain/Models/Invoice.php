<?php

declare(strict_types=1);

namespace Modules\Billing\Domain\Models;

use App\Platform\Support\Money;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Invoice extends Model
{
    use HasUuids;

    public const OPEN = 'open';

    public const PAID = 'paid';

    public const VOID = 'void';

    public const UNCOLLECTIBLE = 'uncollectible';

    protected $fillable = [
        'organization_id', 'subscription_id', 'number', 'status', 'currency',
        'subtotal', 'tax_rate', 'tax_amount', 'credit_applied', 'total',
        'amount_paid', 'period_start', 'period_end', 'issued_at', 'due_at',
        'paid_at', 'voided_at', 'billing_details', 'pdf_file_id', 'pdf_rendered_at',
    ];

    protected function casts(): array
    {
        return [
            'subtotal' => 'integer',
            'tax_amount' => 'integer',
            'credit_applied' => 'integer',
            'total' => 'integer',
            'amount_paid' => 'integer',
            'tax_rate' => 'float',
            'billing_details' => 'array',
            'period_start' => 'immutable_datetime',
            'period_end' => 'immutable_datetime',
            'issued_at' => 'immutable_datetime',
            'due_at' => 'immutable_datetime',
            'paid_at' => 'immutable_datetime',
            'voided_at' => 'immutable_datetime',
            'pdf_rendered_at' => 'immutable_datetime',
        ];
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class);
    }

    public function intents(): HasMany
    {
        return $this->hasMany(PaymentIntent::class);
    }

    public function totalMoney(): Money
    {
        return Money::of($this->total, $this->currency);
    }

    public function outstanding(): Money
    {
        return Money::of(max(0, $this->total - $this->amount_paid), $this->currency);
    }

    public function isPayable(): bool
    {
        return $this->status === self::OPEN && $this->outstanding()->isPositive();
    }
}
