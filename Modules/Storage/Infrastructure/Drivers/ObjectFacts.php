<?php

declare(strict_types=1);

namespace Modules\Storage\Infrastructure\Drivers;

/**
 * Ce que le magasin dit réellement d'un objet.
 *
 * **Écrase ce que le client avait déclaré.** C'est la règle déjà éprouvée sur
 * les callbacks de paiement : le corps ne décide jamais de l'issue.
 *
 * Sans cette vérification, le contrôle de type et le quota ne borneraient rien :
 * ils s'appliqueraient à une déclaration, pas à un fichier.
 */
final readonly class ObjectFacts
{
    public function __construct(
        public int $size,
        public string $mimeType,
        public ?string $checksum = null,
    ) {}
}
