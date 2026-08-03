<?php

declare(strict_types=1);

namespace Modules\Identity\Infrastructure\OAuth;

/**
 * Frontière avec les fournisseurs OAuth externes.
 *
 * L'interface existe pour que le domaine ne dépende pas de Socialite, et pour
 * que les tests n'aient jamais besoin du réseau.
 */
interface OAuthGateway
{
    public function authorizationUrl(string $provider, string $state, string $redirectUri): string;

    /**
     * Échange le code d'autorisation contre l'identité du porteur.
     */
    public function identityFromCode(string $provider, string $code, string $redirectUri): OAuthIdentity;
}
