<?php

declare(strict_types=1);

namespace Modules\Storage\Domain\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Les octets consommés, par organisation et par destination.
 *
 * ## Pourquoi une table plutôt qu'un `SUM()`
 *
 * Le quota est vérifié à chaque déclaration. Une somme sur les fichiers d'une
 * organisation reste rapide à dix mille lignes et cesse de l'être à dix
 * millions — et elle le cesse d'abord pour le plus gros client, celui qui paie
 * le plus.
 *
 * Le compteur n'est qu'une lecture rapide, et il doit pouvoir être jeté :
 * `files` reste la vérité, `storage:recount` le rebâtit.
 *
 * ## Pourquoi ventilé par destination
 *
 * Le quota ne porte que sur nos comptes. Les octets posés sur la destination
 * d'un client ou d'un produit externe sont comptés — donc rapportables — mais
 * jamais opposables : il paie sa propre facture cloud.
 */
final class StorageUsage extends Model
{
    protected $table = 'storage_usage';

    public $incrementing = false;

    public const CREATED_AT = null;

    protected $primaryKey = null;

    protected $fillable = ['organization_id', 'destination_id', 'bytes_used', 'file_count'];

    protected function casts(): array
    {
        return [
            'bytes_used' => 'integer',
            'file_count' => 'integer',
            'updated_at' => 'datetime',
        ];
    }
}
