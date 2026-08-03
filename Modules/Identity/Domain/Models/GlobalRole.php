<?php

declare(strict_types=1);

namespace Modules\Identity\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

final class GlobalRole extends Model
{
    use HasUuids;

    public const OWNER = 'owner';

    public const ADMIN = 'admin';

    public const BILLING_MANAGER = 'billing_manager';

    public const MEMBER = 'member';

    protected $fillable = ['name', 'slug', 'description', 'is_system'];

    protected function casts(): array
    {
        return ['is_system' => 'boolean'];
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(
            GlobalPermission::class,
            'role_permissions',
            'global_role_id',
            'global_permission_id',
        );
    }
}
