<?php

declare(strict_types=1);

namespace Modules\Storage\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Où poser les octets d'une organisation.
 *
 * `owner_type` nul vaut « tous les types ». Une règle typée l'emporte sur une
 * règle attrape-tout — voir docs/03-services/storage/06-destinations.md §4.
 *
 * Ces règles **ne déplacent rien**. Une règle ajoutée ou modifiée ne vaut que
 * pour les fichiers à venir : ceux qui existent portent déjà leur destination,
 * écrite sur leur ligne. L'intuition dit le contraire — « je change la
 * destination de ce client » ressemble à un déménagement — et croire l'inverse
 * ferait chercher des fichiers là où ils ne sont pas.
 */
final class StoragePlacement extends Model
{
    use HasUuids;

    protected $table = 'storage_placements';

    protected $fillable = ['organization_id', 'owner_type', 'destination_id'];

    public function destination(): BelongsTo
    {
        return $this->belongsTo(Destination::class, 'destination_id');
    }
}
