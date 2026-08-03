<?php

declare(strict_types=1);

namespace Modules\Identity\Infrastructure\OAuth;

use App\Platform\Exceptions\DomainException;
use Illuminate\Support\Str;
use Laravel\Socialite\Contracts\Factory as Socialite;
use Throwable;

final class SocialiteGateway implements OAuthGateway
{
    public function __construct(private readonly Socialite $socialite) {}

    public function authorizationUrl(string $provider, string $state, string $redirectUri): string
    {
        return $this->driver($provider, $redirectUri)
            ->stateless()
            ->with(['state' => $state])
            ->redirect()
            ->getTargetUrl();
    }

    public function identityFromCode(string $provider, string $code, string $redirectUri): OAuthIdentity
    {
        try {
            $driver = $this->driver($provider, $redirectUri)->stateless();

            $accessToken = $driver->getAccessTokenResponse($code)['access_token'];

            // Le jeton du fournisseur n'est jamais conservé : il ne sert qu'à
            // lire l'identité, une seule fois.
            $user = $driver->userFromToken($accessToken);
        } catch (Throwable) {
            throw new DomainException(
                'OAUTH_PROVIDER_ERROR',
                __('identity::messages.oauth_provider_unreachable'),
                503,
            );
        }

        [$firstName, $lastName] = self::splitName($user->getName() ?? '');

        return new OAuthIdentity(
            providerId: (string) $user->getId(),
            email: $user->getEmail(),
            firstName: $firstName,
            lastName: $lastName,
            avatarUrl: $user->getAvatar(),
        );
    }

    private function driver(string $provider, string $redirectUri)
    {
        return $this->socialite->driver($provider)->redirectUrl($redirectUri);
    }

    /**
     * Les fournisseurs ne renvoient qu'un nom complet : on le coupe au premier
     * espace, faute de mieux. L'utilisateur pourra corriger son profil.
     *
     * @return array{string, string}
     */
    private static function splitName(string $fullName): array
    {
        $fullName = trim($fullName);

        if ($fullName === '') {
            return ['', ''];
        }

        return [
            Str::before($fullName, ' '),
            trim(Str::after($fullName, ' ')) ?: '',
        ];
    }
}
