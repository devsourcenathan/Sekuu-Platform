<?php

declare(strict_types=1);

namespace Modules\Notify\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class NotificationPreference extends Model
{
    use HasUuids;

    protected $fillable = [
        'user_id',
        'organization_id',
        'category',
        'channel',
        'enabled',
    ];

    protected function casts(): array
    {
        return ['enabled' => 'boolean'];
    }
}
