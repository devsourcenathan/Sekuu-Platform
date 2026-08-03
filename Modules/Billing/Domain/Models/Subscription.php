<?php

declare(strict_types=1);

namespace Modules\Billing\Domain\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Billing\Domain\SubscriptionStatus;

/**
 * @see docs/04-decisions/adr-0007-mobile-money-prepaid-subscriptions.md
 */
final class Subscription extends Model
{
    use HasUuids;

    protected $fillable = [
        'organization_id', 'plan_id', 'plan_price_id', 'status',
        'current_period_start', 'current_period_end', 'trial_ends_at',
        'grace_ends_at', 'cancelled_at', 'cancel_at_period_end',
        'cancellation_reason', 'pending_plan_id', 'pending_plan_price_id',
        'suspended_at', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => SubscriptionStatus::class,
            'current_period_start' => 'immutable_datetime',
            'current_period_end' => 'immutable_datetime',
            'trial_ends_at' => 'immutable_datetime',
            'grace_ends_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'suspended_at' => 'immutable_datetime',
            'cancel_at_period_end' => 'boolean',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function price(): BelongsTo
    {
        return $this->belongsTo(PlanPrice::class, 'plan_price_id');
    }

    public function pendingPlan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'pending_plan_id');
    }

    public function pendingPrice(): ?PlanPrice
    {
        return $this->pending_plan_price_id === null
            ? null
            : PlanPrice::query()->find($this->pending_plan_price_id);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function scopeAlive(Builder $query): Builder
    {
        return $query->whereIn('status', SubscriptionStatus::aliveValues());
    }

    public function grantsAccess(): bool
    {
        return $this->status->grantsAccess();
    }

    /**
     * Fraction de période encore due au client, entre 0 et 1.
     *
     * Sert au crédit de proration lors d'une montée en gamme. Un essai ne
     * produit aucun crédit : rien n'a été payé, et créditer du vide reviendrait
     * à offrir de l'argent à qui change de plan pendant son essai.
     */
    public function unusedRatio(): float
    {
        if ($this->status === SubscriptionStatus::Trialing) {
            return 0.0;
        }

        $total = $this->current_period_start->diffInSeconds($this->current_period_end);

        if ($total <= 0) {
            return 0.0;
        }

        $remaining = now()->diffInSeconds($this->current_period_end, false);

        return max(0.0, min(1.0, $remaining / $total));
    }
}
