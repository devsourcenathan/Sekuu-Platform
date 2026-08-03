<?php

declare(strict_types=1);

namespace Modules\Identity\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class PasswordHistory extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $table = 'password_histories';

    protected $fillable = ['user_id', 'password_hash'];

    protected $hidden = ['password_hash'];
}
