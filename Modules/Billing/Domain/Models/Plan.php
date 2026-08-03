<?php

declare(strict_types=1);

namespace Modules\Billing\Domain\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Plan extends Model
{
    use HasUuids;

    protected $fillable = [
        'key', 'name', 'description', 'status', 'is_public',
        'trial_days', 'sort_order', 'limits',
    ];

    protected function casts(): array
    {
        return [
            'limits' => 'array',
            'is_public' => 'boolean',
            'trial_days' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    public function prices(): HasMany
    {
        return $this->hasMany(PlanPrice::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(PlanProduct::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    public function isArchived(): bool
    {
        return $this->status === 'archived';
    }

    public function offersTrial(): bool
    {
        return $this->trial_days > 0;
    }

    /**
     * Limite du plan. `null` a **deux** sens qu'il faut distinguer :
     * la clé absente signifie « non couvert », la valeur nulle « illimité ».
     */
    public function limit(string $key): int|null|false
    {
        $limits = $this->limits ?? [];

        return array_key_exists($key, $limits) ? $limits[$key] : false;
    }
}
