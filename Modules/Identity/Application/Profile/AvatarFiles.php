<?php

declare(strict_types=1);

namespace Modules\Identity\Application\Profile;

use App\Platform\Contracts\AttachedFile;
use App\Platform\Contracts\FileActor;
use App\Platform\Contracts\FileOwner;
use App\Platform\Contracts\FilePolicy;
use App\Platform\Contracts\FileRef;
use Modules\Identity\Domain\Models\Membership;
use Modules\Identity\Domain\Models\User;

/**
 * La photo de profil d'un utilisateur.
 *
 * Le premier fichier **déposé par une personne** de la plateforme : le PDF de
 * facture, lui, est produit par le serveur. C'est donc le premier usage réel du
 * chemin en trois temps — déclarer, écrire, confirmer.
 *
 * @see docs/03-services/storage/05-integration.md
 */
final class AvatarFiles implements FileOwner
{
    public const TYPE = 'identity.avatar';

    /**
     * Deux mégaoctets, et une liste close de types.
     *
     * La liste est close parce qu'un avatar est **affiché** : c'est le seul
     * endroit de la plateforme où un fichier déposé par un tiers est rendu en
     * ligne plutôt qu'en pièce jointe. Un SVG y figurerait naturellement, et
     * un SVG est un document qui peut porter du script — il en est
     * délibérément absent.
     *
     * @var list<string>
     */
    private const TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    /**
     * On ne dépose une photo que sur **son propre** profil.
     *
     * Un administrateur d'organisation n'y a pas droit non plus : changer le
     * visage de quelqu'un d'autre n'est pas une opération d'administration,
     * c'est une usurpation.
     */
    public function policy(FileRef $ref, FileActor $actor): FilePolicy
    {
        if ($actor->id !== $ref->id || ! User::query()->whereKey($ref->id)->exists()) {
            return FilePolicy::refuse();
        }

        return FilePolicy::allow(
            mimeTypes: self::TYPES,
            maxBytes: 2 * 1024 * 1024,
        );
    }

    /**
     * Soi-même, et ses collègues.
     *
     * Un avatar que personne d'autre ne voit ne sert à rien ; un avatar que
     * tout le monde voit est un annuaire ouvert. Le partage d'organisation est
     * la frontière juste, et Identity est le seul module qui puisse la
     * calculer.
     */
    public function mayRead(FileRef $ref, FileActor $actor): bool
    {
        if ($actor->isSystem() || $actor->id === $ref->id) {
            return true;
        }

        if ($actor->id === null) {
            return false;
        }

        return Membership::query()
            ->where('user_id', $ref->id)
            ->whereIn('organization_id', Membership::query()
                ->where('user_id', $actor->id)
                ->select('organization_id'))
            ->exists();
    }

    /**
     * La photo devient celle du profil.
     *
     * L'ancienne n'est pas effacée ici : la supprimer dans la transaction de
     * confirmation ferait dépendre l'attachement d'un appel au magasin, qui
     * peut échouer. Le balayage s'en charge une fois la suppression demandée —
     * et en attendant, une photo de trop coûte deux cents kilo-octets.
     */
    public function attached(AttachedFile $file): void
    {
        User::query()->whereKey($file->owner->id)->update(['avatar_file_id' => $file->fileId]);
    }

    public function detached(AttachedFile $file): void
    {
        User::query()
            ->whereKey($file->owner->id)
            ->where('avatar_file_id', $file->fileId)
            ->update(['avatar_file_id' => null]);
    }
}
