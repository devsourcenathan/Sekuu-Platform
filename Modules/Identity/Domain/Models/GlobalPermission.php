<?php

declare(strict_types=1);

namespace Modules\Identity\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class GlobalPermission extends Model
{
    use HasUuids;

    protected $fillable = ['code', 'description'];
}
