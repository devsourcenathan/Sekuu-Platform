<?php

declare(strict_types=1);

namespace Modules\Identity\Domain;

/**
 * Contexte porté par un access token.
 *
 * Il ne contient jamais de donnée personnelle (ni email, ni téléphone, ni nom) :
 * un JWT est signé, pas chiffré, et son contenu est lisible par quiconque le
 * possède.
 *
 * @see docs/02-standards/security.md
 */
final readonly class TokenContext
{
    /**
     * @param  list<string>  $roles
     * @param  list<string>  $scopes
     * @param  list<string>  $products
     */
    public function __construct(
        public string $userId,
        public string $sessionId,
        public ?string $organizationId = null,
        public ?string $workspaceId = null,
        public array $roles = [],
        public array $scopes = [],
        public array $products = [],
        public string $language = 'fr',
        public ?string $tokenId = null,
    ) {}

    /**
     * Un token sans organisation active ne donne accès qu'aux routes de profil.
     */
    public function hasOrganization(): bool
    {
        return $this->organizationId !== null;
    }

    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->scopes, true);
    }

    public function hasRole(string $role): bool
    {
        return in_array($role, $this->roles, true);
    }

    public function canAccessProduct(string $productSlug): bool
    {
        return in_array($productSlug, $this->products, true);
    }
}
