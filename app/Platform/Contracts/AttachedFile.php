<?php

declare(strict_types=1);

namespace App\Platform\Contracts;

/**
 * Ce que la couche de stockage remet au propriétaire quand les octets sont
 * constatés — ou quand ils s'en vont.
 *
 * Volontairement pauvre. Ni le chemin de l'objet, ni la destination, ni la
 * moindre URL : le propriétaire n'a besoin que de savoir **quoi** est rattaché
 * à **quoi**. Lui donner de quoi lire les octets directement contournerait le
 * contrôle d'accès dont il est lui-même l'auteur.
 *
 * @see docs/03-services/storage/04-events.md
 */
final readonly class AttachedFile
{
    public function __construct(
        public string $fileId,
        public FileRef $owner,
        public ?string $organizationId,
        public string $name,
        public string $mimeType,
        public int $size,
        public ?string $checksum = null,
    ) {}
}
