<?php

declare(strict_types=1);

namespace Modules\Identity\Domain\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

final class Workspace extends Model
{
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'organization_id',
        'name',
        'slug',
        'settings',
        'status',
    ];

    protected function casts(): array
    {
        return ['settings' => 'array'];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function members(): HasMany
    {
        return $this->hasMany(WorkspaceMember::class);
    }

    /**
     * Toute lecture de workspace passe par ce filtre : l'organisation vient du
     * token, jamais de la requête.
     */
    public function scopeOfOrganization(Builder $query, string $organizationId): Builder
    {
        return $query->where('organization_id', $organizationId);
    }

    /**
     * Workspaces dont le membership donné est effectivement membre.
     */
    public function scopeVisibleTo(Builder $query, string $membershipId): Builder
    {
        return $query->whereHas('members', fn (Builder $q) => $q->where('membership_id', $membershipId));
    }

    public function hasMember(string $membershipId): bool
    {
        return $this->members()->where('membership_id', $membershipId)->exists();
    }
}
