<?php

declare(strict_types=1);

namespace Modules\Identity\Infrastructure\Auth;

use App\Platform\Exceptions\DomainException;
use App\Platform\Http\Middleware\ResolveLocale;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Modules\Identity\Domain\AuthenticatedContext;
use Modules\Identity\Domain\Models\User;
use Modules\Identity\Domain\Models\UserSession;
use Modules\Identity\Infrastructure\Jwt\AccessTokenVerifier;

/**
 * Résout le contexte authentifié d'une requête à partir de son access token.
 *
 * L'access token est vérifié par signature, sans appel réseau. La session est
 * ensuite consultée pour honorer les révocations : le document de sécurité
 * prévoit une liste de révocation en Redis, absente ici, donc la table
 * `user_sessions` joue ce rôle tant que la plateforme est un monolithe.
 *
 * @see docs/04-decisions/adr-0004-jwt-stateless-tokens.md
 */
final class JwtUserResolver
{
    /**
     * Mémoïsation par requête, et non dans le conteneur : une instance
     * partagée fuirait d'une requête à l'autre.
     *
     * @var array<int, AuthenticatedContext|null>
     */
    private array $resolved = [];

    public function __construct(private readonly AccessTokenVerifier $verifier) {}

    public function user(Request $request): ?User
    {
        return $this->resolve($request)?->user;
    }

    public function resolve(Request $request): ?AuthenticatedContext
    {
        $key = spl_object_id($request);

        if (array_key_exists($key, $this->resolved)) {
            return $this->resolved[$key];
        }

        return $this->resolved[$key] = $this->build($request);
    }

    /**
     * Contexte de la requête courante, ou échec explicite : appelé par les
     * contrôleurs, qui ne s'exécutent que derrière le middleware d'auth.
     */
    public function require(Request $request): AuthenticatedContext
    {
        return $this->resolve($request)
            ?? throw new DomainException('UNAUTHENTICATED', __('platform.authentication_required'), 401);
    }

    private function build(Request $request): ?AuthenticatedContext
    {
        $token = $request->bearerToken();

        if ($token === null || $token === '') {
            return null;
        }

        $context = $this->verifier->verify($token);

        $session = UserSession::query()->find($context->sessionId);

        if ($session === null || ! $session->isUsable()) {
            throw new DomainException('TOKEN_REVOKED', __('identity::messages.session_revoked'), 401);
        }

        $user = User::query()->find($context->userId);

        if ($user === null) {
            throw new DomainException('INVALID_TOKEN', __('identity::messages.token_invalid'), 401);
        }

        if (! $user->isActive()) {
            throw DomainException::forbidden('ACCOUNT_SUSPENDED', __('identity::messages.account_inactive'));
        }

        $this->applyPreferredLocale($user);

        return new AuthenticatedContext($user, $session, $context);
    }

    /**
     * La langue choisie par l'utilisateur prime sur `Accept-Language` : un
     * navigateur envoie sa propre langue sans que l'utilisateur l'ait demandé,
     * alors que le profil est un choix explicite.
     */
    private function applyPreferredLocale(User $user): void
    {
        $locale = (string) ($user->language ?? '');

        if ($locale !== '' && ResolveLocale::isSupported($locale)) {
            App::setLocale($locale);
        }
    }
}
