<?php

declare(strict_types=1);

namespace Modules\Identity\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

final class Membership extends Model
{
    use HasUuids;

    protected $fillable = [
        'user_id',
        'organization_id',
        'status',
        'invited_by',
        'joined_at',
    ];

    protected function casts(): array
    {
        return [
            'joined_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(
            GlobalRole::class,
            'membership_roles',
            'membership_id',
            'global_role_id',
        );
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * @return list<string>
     */
    public function roleSlugs(): array
    {
        return $this->roles->pluck('slug')->values()->all();
    }

    /**
     * Permissions globales dérivées des rôles. Elles deviennent le claim
     * `scopes` du token : jamais de permission métier ici.
     *
     * @return list<string>
     */
    public function scopes(): array
    {
        return $this->roles
            ->loadMissing('permissions')
            ->flatMap(fn (GlobalRole $role) => $role->permissions->pluck('code'))
            ->unique()
            ->sort()
            ->values()
            ->all();
    }
}
