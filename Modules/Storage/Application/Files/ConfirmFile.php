<?php

declare(strict_types=1);

namespace Modules\Storage\Application\Files;

use App\Platform\Contracts\FileActor;
use App\Platform\Events\DomainEvent;
use App\Platform\Exceptions\DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Modules\Storage\Domain\Models\StoredFile;
use Modules\Storage\Infrastructure\Drivers\DriverRegistry;

/**
 * Deuxième temps : constater.
 *
 * ## La déclaration ne fait jamais foi
 *
 * On interroge le magasin — taille réelle, type réel, empreinte réelle — et on
 * écrase ce que le client avait annoncé. C'est la règle déjà éprouvée sur les
 * callbacks de paiement : *le corps ne décide jamais de l'issue*.
 *
 * Sans cette vérification, le contrôle de type et le quota ne borneraient rien
 * du tout : ils s'appliqueraient à une déclaration, pas à un fichier.
 *
 * ## Pourquoi une confirmation explicite plutôt qu'une notification du magasin
 *
 * S3 sait notifier une écriture. Mais cette notification arrive par un canal
 * que nous n'avons pas éprouvé, absent des offres gratuites, et Payments a
 * documenté ce qu'il en coûte d'en dépendre — trois livraisons dans un ordre
 * variable, un corps qui ment sur le statut.
 *
 * Une confirmation demandée par le client est synchrone, attribuable, et
 * testable sans magasin externe.
 *
 * @see docs/03-services/storage/03-api.md
 */
final class ConfirmFile
{
    public function __construct(
        private readonly OwnerRegistry $owners,
        private readonly DriverRegistry $drivers,
        private readonly StorageQuota $quota,
    ) {}

    public function handle(StoredFile $file, FileActor $actor): StoredFile
    {
        // Idempotence : confirmer un fichier déjà prêt rend le même résultat.
        // C'est le passage d'état qui ajuste le compteur, jamais l'appel.
        if ($file->status === StoredFile::READY) {
            return $file;
        }

        if ($file->status === StoredFile::DELETED) {
            throw DomainException::notFound('FILE_NOT_FOUND', __('storage::messages.file_not_found'));
        }

        $destination = $file->destination;
        $facts = $this->drivers->for($destination)->inspect($destination, (string) $file->path);

        if ($facts === null) {
            /*
             * Les octets ne sont pas là.
             *
             * Le fichier **reste** `pending` : c'est une incertitude, pas un
             * refus. Le client peut réessayer avec la même URL tant qu'elle
             * vit, et le balayage nettoiera s'il ne le fait jamais.
             */
            throw DomainException::unprocessable('UPLOAD_INCOMPLETE', __('storage::messages.upload_incomplete'));
        }

        $policy = $this->owners->for($file->owner_type)->policy($file->owner(), $actor);

        /*
         * Non conforme : on efface, et le fichier passe `deleted`.
         *
         * Pas `pending`, contrairement au cas précédent. Il ne s'agit pas d'une
         * incertitude mais d'un refus constaté : le garder en attente
         * inviterait à réessayer une opération qui ne peut pas aboutir.
         */
        if (! $policy->acceptsMimeType($facts->mimeType) || ! $policy->acceptsSize($facts->size)) {
            $this->reject($file);

            throw DomainException::unprocessable(
                $policy->acceptsMimeType($facts->mimeType) ? 'FILE_TOO_LARGE' : 'MIME_TYPE_NOT_ALLOWED',
                __('storage::messages.declared_does_not_match'),
            );
        }

        $this->quota->assertHasRoom($file->organization_id, $destination, $facts->size);

        DB::transaction(function () use ($file, $facts, $destination): void {
            $file->forceFill([
                'mime_type' => $facts->mimeType,
                'size' => $facts->size,
                'checksum' => $facts->checksum,
                'status' => StoredFile::READY,
                'confirmed_at' => now(),
            ])->save();

            $this->quota->adjust($file->organization_id, $destination, $facts->size, 1);

            /*
             * Le propriétaire est prévenu **dans la transaction**, et non par
             * un événement.
             *
             * Confier ce moment à une file créerait une fenêtre où le fichier
             * est prêt et l'objet l'ignore, qu'un consommateur en échec
             * définitif rendrait permanente — une leçon « sans support » alors
             * que les octets sont là.
             */
            $this->owners->for($file->owner_type)->attached($file->toAttachedFile());
        });

        Event::dispatch(new DomainEvent('storage.file.attached', [
            'file_id' => (string) $file->id,
            'organization_id' => $file->organization_id,
            'owner_type' => $file->owner_type,
            'owner_id' => $file->owner_id,
            'mime_type' => $file->mime_type,
            'size' => $file->size,
        ], $file->organization_id));

        return $file;
    }

    private function reject(StoredFile $file): void
    {
        $destination = $file->destination;

        $this->drivers->for($destination)->delete($destination, (string) $file->path);

        $file->forceFill(['status' => StoredFile::DELETED, 'deleted_at' => now()])->save();
    }
}
