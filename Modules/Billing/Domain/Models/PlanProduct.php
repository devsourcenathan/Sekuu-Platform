<?php

declare(strict_types=1);

namespace Modules\Billing\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * `product_id` est une référence **logique** vers Identity, sans clé étrangère :
 * Billing doit rester extractible sans contrainte inter-schémas.
 */
final class PlanProduct extends Model
{
    use HasUuids;

    protected $fillable = ['plan_id', 'product_id'];

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }
}
