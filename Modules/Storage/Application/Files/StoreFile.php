<?php

declare(strict_types=1);

namespace Modules\Storage\Application\Files;

use App\Platform\Contracts\FileActor;
use App\Platform\Contracts\FileRef;
use Modules\Storage\Domain\Models\StoredFile;
use Modules\Storage\Infrastructure\Drivers\DriverRegistry;

/**
 * Écrire des octets que la plateforme produit elle-même.
 *
 * Quand c'est le serveur qui fabrique le fichier — Billing mettant en page une
 * facture — l'URL signée n'a pas d'objet : on écrit, puis on confirme dans la
 * foulée.
 *
 * Le chemin en deux temps de l'ADR-0012 existe pour les octets qui viennent
 * d'ailleurs. Le contourner ici n'affaiblit rien : la contrainte que cette ADR
 * pose est que **la plateforme ne serve pas de mandataire aux octets d'un
 * client**, et ceux-ci ne sont pas ceux d'un client.
 *
 * Tout le reste s'applique sans exception — la politique du propriétaire, la
 * résolution de destination, le quota, et la constatation par le magasin. En
 * particulier, la taille écrite n'est pas celle qu'on croit avoir écrite : elle
 * est relue.
 *
 * @see docs/03-services/storage/05-integration.md
 */
final class StoreFile
{
    public function __construct(
        private readonly DeclareFile $declare,
        private readonly ConfirmFile $confirm,
        private readonly DriverRegistry $drivers,
    ) {}

    public function handle(
        FileRef $owner,
        ?string $organizationId,
        string $name,
        string $contents,
        string $mimeType,
        ?string $destination = null,
    ): StoredFile {
        $actor = FileActor::system($organizationId);

        $declared = $this->declare->handle(
            owner: $owner,
            actor: $actor,
            name: $name,
            mimeType: $mimeType,
            size: strlen($contents),
            destination: $destination,
        );

        $file = $declared->file;
        $target = $file->destination;

        $this->drivers->for($target)->put($target, (string) $file->path, $contents, $mimeType);

        return $this->confirm->handle($file, $actor);
    }
}
