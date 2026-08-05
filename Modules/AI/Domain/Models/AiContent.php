<?php

declare(strict_types=1);

namespace Modules\AI\Domain\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Le contenu, **quand une tâche déclare le conserver** — sinon il n'existe pas.
 *
 * Table séparée, et c'est délibéré. Le registre des générations est consulté en
 * permanence — quota, facturation, supervision — tandis que le contenu ne l'est
 * qu'exceptionnellement, pèse mille fois plus, et s'efface.
 *
 * Les mêler ferait grossir la table chaude et rendrait l'effacement impossible
 * sans réécrire un registre qui doit rester scellé.
 */
final class AiContent extends Model
{
    protected $table = 'ai_contents';

    public $incrementing = false;

    public $timestamps = false;

    protected $primaryKey = 'generation_id';

    protected $keyType = 'string';

    protected $fillable = ['generation_id', 'input', 'output', 'expires_at'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime'];
    }
}
