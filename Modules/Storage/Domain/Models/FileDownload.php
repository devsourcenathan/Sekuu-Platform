<?php

declare(strict_types=1);

namespace Modules\Storage\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Une autorisation de lecture délivrée.
 *
 * **Pas un téléchargement.** Le client récupère les octets auprès du magasin,
 * sans passer par nous : l'accès lui-même nous est invisible.
 *
 * La nuance décide d'un litige. « Ce document a été consulté le 3 août » est
 * faux ; « une autorisation de lecture a été délivrée à cet utilisateur le
 * 3 août, valable dix minutes » est vrai, et suffit à un audit.
 *
 * @see docs/03-services/storage/02-data-model.md
 */
final class FileDownload extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $fillable = ['file_id', 'actor_type', 'actor_id', 'ip', 'expires_at'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime'];
    }

    /**
     * Registre scellé, comme ceux de Payments. Une ligne d'accès qu'on peut
     * réécrire ne prouve rien — et c'est précisément ce qu'on lui demande.
     */
    protected static function booted(): void
    {
        self::updating(function (): never {
            throw new RuntimeException('Le journal des accès est en ajout seul : une ligne délivrée ne se modifie pas.');
        });

        self::deleting(function (): never {
            throw new RuntimeException("Le journal des accès est en ajout seul : une ligne délivrée ne s'efface pas.");
        });
    }
}
