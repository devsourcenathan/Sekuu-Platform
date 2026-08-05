<?php

declare(strict_types=1);

namespace Modules\Storage\Domain\Models;

use App\Platform\Contracts\AttachedFile;
use App\Platform\Contracts\FileRef;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un fichier, de sa déclaration à sa suppression.
 *
 * Nommé `StoredFile` et non `File` : `File` est déjà une façade de Laravel, et
 * un `use` malheureux produirait une erreur incompréhensible.
 *
 * @see docs/03-services/storage/02-data-model.md
 */
final class StoredFile extends Model
{
    use HasUuids;

    protected $table = 'files';

    /**
     * Déclaré, URL délivrée. Les octets ne sont **pas** garantis présents.
     *
     * Ne signifie pas « en cours de téléversement » : ce module n'en sait rien,
     * le client écrit dans le magasin sans lui. Il signifie **on ne sait pas**,
     * exactement comme une intention de paiement expirée — et c'est ce qui rend
     * le balayage obligatoire plutôt qu'optionnel.
     */
    public const PENDING = 'pending';

    /** Les octets sont constatés. Seul état servable. */
    public const READY = 'ready';

    /** La ligne survit, les octets sont partis ou vont partir. */
    public const DELETED = 'deleted';

    protected $fillable = [
        'organization_id', 'owner_type', 'owner_id', 'destination_id', 'path',
        'name', 'mime_type', 'size', 'checksum', 'status', 'visibility',
        'retain_until', 'uploaded_by', 'confirmed_at', 'deleted_at', 'purged_at',
        'metadata',
    ];

    /**
     * `path` n'est jamais rendu par l'API : la clé porte l'identifiant
     * d'organisation, et la publier reviendrait à inviter à deviner celle du
     * voisin.
     */
    protected $hidden = ['path'];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'metadata' => 'array',
            'retain_until' => 'datetime',
            'confirmed_at' => 'datetime',
            'deleted_at' => 'datetime',
            'purged_at' => 'datetime',
        ];
    }

    public function destination(): BelongsTo
    {
        return $this->belongsTo(Destination::class, 'destination_id');
    }

    public function owner(): FileRef
    {
        return new FileRef($this->owner_type, $this->owner_id);
    }

    public function isReady(): bool
    {
        return $this->status === self::READY;
    }

    /**
     * La rétention est une obligation, pas une préférence : aucun paramètre ne
     * passe outre, aucune permission ne l'emporte. Une obligation légale qu'un
     * rôle suffit à contourner n'est pas une obligation.
     */
    public function isRetained(): bool
    {
        return $this->retain_until !== null && $this->retain_until->isFuture();
    }

    /**
     * Ce que le propriétaire reçoit : ni chemin, ni destination, ni URL.
     */
    public function toAttachedFile(): AttachedFile
    {
        return new AttachedFile(
            fileId: (string) $this->id,
            owner: $this->owner(),
            organizationId: $this->organization_id,
            name: (string) $this->name,
            mimeType: (string) ($this->mime_type ?? 'application/octet-stream'),
            size: (int) ($this->size ?? 0),
            checksum: $this->checksum,
        );
    }
}
