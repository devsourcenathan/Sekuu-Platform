<?php

declare(strict_types=1);

namespace App\Platform\Contracts;

/**
 * Limite d'un plan pour une ressource donnée.
 *
 * Trois états, et non deux — c'est toute la raison d'être de cet objet :
 *
 *  - **plafonnée** : une valeur ;
 *  - **illimitée** : le plan couvre la ressource sans borne ;
 *  - **non couverte** : le plan n'ouvre pas cette ressource du tout.
 *
 * Un simple `?int` confondrait les deux dernières, et « illimité » se lirait
 * comme « interdit » — ou l'inverse, ce qui serait pire.
 *
 * @see docs/03-services/billing/02-data-model.md
 */
final readonly class PlanLimit
{
    private function __construct(
        public ?int $value,
        public bool $covered,
    ) {}

    public static function of(int $value): self
    {
        return new self($value, true);
    }

    public static function unlimited(): self
    {
        return new self(null, true);
    }

    public static function notCovered(): self
    {
        return new self(0, false);
    }

    /**
     * Aucun abonnement, ou abonnement fermé.
     *
     * Traité comme « non couvert » : sans droit d'accès, il n'y a rien à
     * plafonner. Le blocage est de toute façon appliqué en amont par Identity,
     * sur `organization_products`.
     */
    public static function noSubscription(): self
    {
        return self::notCovered();
    }

    public function allows(int $current): bool
    {
        if (! $this->covered) {
            return false;
        }

        return $this->value === null || $current < $this->value;
    }

    public function isUnlimited(): bool
    {
        return $this->covered && $this->value === null;
    }
}
