<?php

declare(strict_types=1);

namespace App\Platform\Contracts;

/**
 * Ce qu'un paiement règle, désigné sans être décrit.
 *
 * `type` suit `{module}.{ressource}` — `billing.invoice`, `learn.enrollment` —
 * la convention déjà en vigueur pour les événements de domaine. La couche de
 * paiement ne l'interprète jamais : elle le porte, l'indexe, et le remet à un
 * résolveur.
 */
final readonly class PayableRef
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
