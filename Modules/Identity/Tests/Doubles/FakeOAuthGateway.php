<?php

declare(strict_types=1);

namespace Modules\Identity\Tests\Doubles;

use App\Platform\Exceptions\DomainException;
use Modules\Identity\Infrastructure\OAuth\OAuthGateway;
use Modules\Identity\Infrastructure\OAuth\OAuthIdentity;

/**
 * Passerelle OAuth de test : aucun appel réseau.
 */
final class FakeOAuthGateway implements OAuthGateway
{
    private ?OAuthIdentity $identity = null;

    private bool $shouldFail = false;

    public function willReturn(OAuthIdentity $identity): self
    {
        $this->identity = $identity;

        return $this;
    }

    public function willFail(): self
    {
        $this->shouldFail = true;

        return $this;
    }

    public function authorizationUrl(string $provider, string $state, string $redirectUri): string
    {
        return "https://accounts.example.test/{$provider}/authorize?state={$state}&redirect_uri=".urlencode($redirectUri);
    }

    public function identityFromCode(string $provider, string $code, string $redirectUri): OAuthIdentity
    {
        if ($this->shouldFail) {
            throw new DomainException(
                'OAUTH_PROVIDER_ERROR',
                'The identity provider could not be reached.',
                503,
            );
        }

        return $this->identity ?? new OAuthIdentity(
            providerId: 'provider-user-1',
            email: 'nathan@sekuu.com',
            firstName: 'Nathan',
            lastName: 'Tchinda',
        );
    }
}
