<?php

declare(strict_types=1);

namespace Modules\Identity\Domain\Models;

use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\Access\Authorizable;

/**
 * Un utilisateur existe une seule fois dans tout l'écosystème.
 *
 * @see docs/03-services/identity/02-data-model.md
 */
final class User extends Model implements AuthenticatableContract
{
    use Authenticatable;
    use Authorizable;
    use HasUuids;
    use SoftDeletes;

    protected $table = 'users';

    protected $fillable = [
        'first_name',
        'last_name',
        'email',
        'phone',
        'avatar_url',
        'avatar_file_id',
        'language',
        'timezone',
    ];

    protected $hidden = [
        'password_hash',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'phone_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password_hash' => 'hashed',
        ];
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(UserSession::class);
    }

    public function getAuthPassword(): string
    {
        return (string) $this->password_hash;
    }

    public function getAuthPasswordName(): string
    {
        return 'password_hash';
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function fullName(): string
    {
        return trim($this->first_name.' '.$this->last_name);
    }

    /**
     * Membership actif dans une organisation donnée, rôles et permissions
     * chargés — c'est la brique du contexte porté par le token.
     */
    public function activeMembershipIn(string $organizationId): ?Membership
    {
        return $this->memberships()
            ->where('organization_id', $organizationId)
            ->where('status', 'active')
            ->with(['organization', 'roles.permissions'])
            ->first();
    }
}
