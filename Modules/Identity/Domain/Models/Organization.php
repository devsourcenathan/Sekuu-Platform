<?php

declare(strict_types=1);

namespace Modules\Identity\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

final class Organization extends Model
{
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'name',
        'slug',
        'country',
        'currency',
        'timezone',
        'locale',
        'logo_url',
    ];

    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(OrganizationProduct::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Slugs des produits auxquels l'organisation a effectivement accès
     * aujourd'hui. C'est ce qui alimente le claim `products` du token.
     *
     * @return list<string>
     */
    public function activeProductSlugs(): array
    {
        return $this->products()
            ->where('status', 'active')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->with('product')
            ->get()
            ->pluck('product.slug')
            ->filter()
            ->values()
            ->all();
    }
}
