<?php

declare(strict_types=1);

namespace Modules\Billing\Domain\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Billing\Domain\Money;

final class PlanPrice extends Model
{
    use HasUuids;

    protected $fillable = [
        'plan_id', 'currency', 'amount', 'interval', 'interval_count', 'status',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'interval_count' => 'integer',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    public function money(): Money
    {
        return Money::of($this->amount, $this->currency);
    }

    public function advance(CarbonImmutable $from): CarbonImmutable
    {
        return $this->interval === 'year'
            ? $from->addYears($this->interval_count)
            : $from->addMonths($this->interval_count);
    }
}
