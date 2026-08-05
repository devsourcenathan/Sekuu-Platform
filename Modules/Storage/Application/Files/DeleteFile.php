<?php

declare(strict_types=1);

namespace Modules\Storage\Application\Files;

use App\Platform\Contracts\FileActor;
use App\Platform\Events\DomainEvent;
use App\Platform\Exceptions\DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Modules\Storage\Domain\Models\StoredFile;

/**
 * Supprimer — logiquement.
 *
 * ## Pourquoi les octets ne partent pas tout de suite
 *
 * Deux raisons. Un `DELETE` accidentel reste réparable pendant quelques jours.
 * Et un effacement dans le magasin qui échouerait au milieu d'une transaction
 * de base laisserait les deux en désaccord — la base dit présent, le magasin
 * dit absent, et rien ne le signalerait.
 *
 * Le balayage fait partir les octets, plus tard, sans transaction à tenir.
 *
 * @see docs/03-services/storage/03-api.md
 */
final class DeleteFile
{
    public function __construct(
        private readonly OwnerRegistry $owners,
        private readonly StorageQuota $quota,
    ) {}

    public function handle(StoredFile $file, FileActor $actor): void
    {
        if (! $actor->mayTouch($file->owner_type)
            || ! $this->owners->for($file->owner_type)->mayRead($file->owner(), $actor)) {
            throw DomainException::notFound('FILE_NOT_FOUND', __('storage::messages.file_not_found'));
        }

        if ($file->status === StoredFile::DELETED) {
            return;
        }

        /*
         * La rétention l'emporte sur tout.
         *
         * Aucun paramètre ne passe outre, aucune permission ne l'emporte, et
         * l'acteur système n'y échappe pas non plus. Une obligation légale
         * qu'un rôle suffit à contourner n'est pas une obligation.
         */
        if ($file->isRetained()) {
            throw new DomainException(
                'FILE_RETAINED',
                __('storage::messages.file_retained', ['date' => $file->retain_until->toDateString()]),
                409,
                ['retain_until' => $file->retain_until->toIso8601String()],
            );
        }

        $attached = $file->toAttachedFile();
        $wasReady = $file->isReady();
        $size = (int) ($file->size ?? 0);
        $destination = $file->destination;

        DB::transaction(function () use ($file, $attached, $wasReady, $size, $destination): void {
            $file->forceFill(['status' => StoredFile::DELETED, 'deleted_at' => now()])->save();

            // Le quota est rendu à la suppression logique, pas à l'effacement :
            // le client ne doit pas attendre le balayage pour reprendre sa
            // place.
            if ($wasReady) {
                $this->quota->adjust($file->organization_id, $destination, -$size, -1);
            }

            $this->owners->for($file->owner_type)->detached($attached);
        });

        Event::dispatch(new DomainEvent('storage.file.deleted', [
            'file_id' => (string) $file->id,
            'organization_id' => $file->organization_id,
            'owner_type' => $file->owner_type,
            'owner_id' => $file->owner_id,
        ], $file->organization_id));
    }
}
