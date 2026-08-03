<?php

declare(strict_types=1);

namespace Modules\Identity\Application\OAuth;

use App\Platform\Exceptions\DomainException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Identity\Domain\Models\OAuthAccount;
use Modules\Identity\Domain\Models\User;
use Modules\Identity\Infrastructure\OAuth\OAuthGateway;
use Modules\Identity\Infrastructure\OAuth\OAuthIdentity;

/**
 * Connexion via un fournisseur externe.
 *
 * @see docs/03-services/identity/03-api.md
 */
final class OAuthFlow
{
    private const STATE_PREFIX = 'identity:oauth:state:';

    public function __construct(private readonly OAuthGateway $gateway) {}

    /**
     * @return array{authorization_url: string, state: string}
     */
    public function start(string $provider): array
    {
        $this->assertProviderIsSupported($provider);

        // Le paramètre `state` protège du CSRF sur le retour du fournisseur.
        // L'API étant sans état, il est conservé côté serveur dans le cache
        // plutôt que dans une session.
        $state = Str::random(40);

        Cache::put(
            self::STATE_PREFIX.$state,
            $provider,
            (int) config('identity.oauth.state_ttl'),
        );

        return [
            'authorization_url' => $this->gateway->authorizationUrl(
                $provider,
                $state,
                $this->redirectUri($provider),
            ),
            'state' => $state,
        ];
    }

    public function complete(string $provider, string $code, string $state): OAuthOutcome
    {
        $this->assertProviderIsSupported($provider);
        $this->consumeState($provider, $state);

        $identity = $this->gateway->identityFromCode($provider, $code, $this->redirectUri($provider));

        return DB::transaction(fn (): OAuthOutcome => $this->resolveUser($provider, $identity));
    }

    private function resolveUser(string $provider, OAuthIdentity $identity): OAuthOutcome
    {
        $existingLink = OAuthAccount::query()
            ->where('provider', $provider)
            ->where('provider_id', $identity->providerId)
            ->first();

        if ($existingLink !== null) {
            $user = $existingLink->user()->firstOrFail();

            $this->assertUsable($user);

            return new OAuthOutcome($user, accountCreated: false, accountLinked: false);
        }

        if ($identity->email === null || $identity->email === '') {
            throw DomainException::unprocessable(
                'OAUTH_PROVIDER_ERROR',
                __('identity::messages.oauth_no_email'),
            );
        }

        $email = mb_strtolower($identity->email);
        $existingUser = User::query()->where('email', $email)->first();

        if ($existingUser !== null) {
            return $this->linkToExistingUser($provider, $identity, $existingUser);
        }

        return $this->createUser($provider, $identity, $email);
    }

    private function linkToExistingUser(string $provider, OAuthIdentity $identity, User $user): OAuthOutcome
    {
        // Rattacher automatiquement sur la seule foi de l'adresse permettrait
        // une prise de contrôle si le fournisseur ne vérifie pas ses emails.
        if (! $this->emailIsTrusted($provider)) {
            throw DomainException::conflict(
                'OAUTH_EMAIL_TAKEN',
                __('identity::messages.oauth_email_taken'),
            );
        }

        $this->assertUsable($user);

        $this->link($provider, $identity, $user);

        if ($user->email_verified_at === null) {
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        return new OAuthOutcome($user, accountCreated: false, accountLinked: true);
    }

    private function createUser(string $provider, OAuthIdentity $identity, string $email): OAuthOutcome
    {
        $user = new User([
            'first_name' => $identity->firstName ?: 'Utilisateur',
            'last_name' => $identity->lastName ?: '',
            'email' => $email,
            'avatar_url' => $identity->avatarUrl,
        ]);

        // Aucun mot de passe : le compte se connecte uniquement via le
        // fournisseur, tant qu'il n'en définit pas un.
        $user->password_hash = null;

        if ($this->emailIsTrusted($provider)) {
            $user->email_verified_at = now();
        }

        $user->save();

        $this->link($provider, $identity, $user);

        return new OAuthOutcome($user, accountCreated: true, accountLinked: true);
    }

    private function link(string $provider, OAuthIdentity $identity, User $user): void
    {
        $alreadyLinked = OAuthAccount::query()
            ->where('user_id', $user->id)
            ->where('provider', $provider)
            ->exists();

        if ($alreadyLinked) {
            throw DomainException::conflict(
                'OAUTH_ACCOUNT_ALREADY_LINKED',
                __('identity::messages.oauth_already_linked', ['provider' => $provider]),
            );
        }

        OAuthAccount::create([
            'user_id' => $user->id,
            'provider' => $provider,
            'provider_id' => $identity->providerId,
            'email' => $identity->email,
        ]);
    }

    private function consumeState(string $provider, string $state): void
    {
        $key = self::STATE_PREFIX.$state;
        $expected = Cache::pull($key);

        // À usage unique : un state rejoué est refusé.
        if ($expected !== $provider) {
            throw new DomainException(
                'OAUTH_STATE_INVALID',
                __('identity::messages.oauth_state_invalid'),
                400,
            );
        }
    }

    private function assertUsable(User $user): void
    {
        if (! $user->isActive()) {
            throw DomainException::forbidden('ACCOUNT_SUSPENDED', __('identity::messages.account_inactive'));
        }
    }

    private function assertProviderIsSupported(string $provider): void
    {
        if (! in_array($provider, (array) config('identity.oauth.providers'), true)) {
            throw DomainException::unprocessable(
                'OAUTH_PROVIDER_NOT_SUPPORTED',
                __('identity::messages.oauth_provider_unsupported'),
            );
        }
    }

    private function emailIsTrusted(string $provider): bool
    {
        return in_array($provider, (array) config('identity.oauth.trusted_email_providers'), true);
    }

    private function redirectUri(string $provider): string
    {
        return rtrim((string) config('app.url'), '/')."/api/v1/oauth/{$provider}/callback";
    }
}
