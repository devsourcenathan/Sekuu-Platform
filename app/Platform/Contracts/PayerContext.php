<?php

declare(strict_types=1);

namespace App\Platform\Contracts;

/**
 * Qui paie.
 *
 * Distinct de qui **encaisse**, que porte la cotation : confondre les deux
 * fonctionne tant qu'il n'y a qu'un vendeur, et devient faux dès qu'un tiers
 * encaisse via la plateforme.
 *
 * `initiatedBy` est la personne qui a cliqué, qui n'est pas toujours le payeur :
 * un administrateur règle la facture de son organisation.
 */
final readonly class PayerContext
{
    public const ORGANIZATION = 'identity.organization';

    public const USER = 'identity.user';

    public function __construct(
        public string $type,
        public string $id,
        public ?string $initiatedBy = null,
    ) {}

    public static function organization(string $organizationId, ?string $initiatedBy = null): self
    {
        return new self(self::ORGANIZATION, $organizationId, $initiatedBy);
    }

    public static function user(string $userId): self
    {
        return new self(self::USER, $userId, $userId);
    }

    public function isOrganization(): bool
    {
        return $this->type === self::ORGANIZATION;
    }
}
