<?php

declare(strict_types=1);

namespace Modules\AI\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Quel compte pour quelle organisation.
 *
 * Ces règles **ne déplacent rien** : une génération porte déjà son
 * `account_id`, et une règle modifiée ne vaut que pour les suivantes.
 */
final class AiPlacement extends Model
{
    use HasUuids;

    protected $table = 'ai_placements';

    protected $fillable = ['organization_id', 'task', 'account_id'];

    public function account(): BelongsTo
    {
        return $this->belongsTo(AiAccount::class, 'account_id');
    }
}
