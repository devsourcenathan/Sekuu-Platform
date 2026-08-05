<?php

declare(strict_types=1);

namespace App\Platform\Contracts;

/**
 * L'objet auquel un fichier est rattaché, désigné sans être décrit.
 *
 * `type` suit `{module}.{ressource}` — `billing.invoice`, `learn.lesson` — la
 * convention déjà en vigueur pour les événements de domaine et pour
 * {@see PayableRef}. La couche de stockage ne l'interprète jamais : elle le
 * porte, l'indexe, et le remet à un résolveur.
 *
 * @see docs/03-services/storage/01-overview.md
 */
final readonly class FileRef
{
    public function __construct(
        public string $type,
        public string $id,
    ) {}

    public function equals(self $other): bool
    {
        return $this->type === $other->type && $this->id === $other->id;
    }

    public function __toString(): string
    {
        return $this->type.':'.$this->id;
    }
}
