<?php

declare(strict_types=1);

namespace Modules\Identity\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

final class Product extends Model
{
    use HasUuids;
    use SoftDeletes;

    protected $fillable = ['name', 'slug', 'description', 'icon_url', 'status'];
}
