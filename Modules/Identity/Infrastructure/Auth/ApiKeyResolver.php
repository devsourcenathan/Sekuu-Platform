<?php

declare(strict_types=1);

namespace Modules\Identity\Infrastructure\Auth;

use App\Platform\Exceptions\DomainException;
use Illuminate\Http\Request;
use Modules\Identity\Domain\ApiKeyContext;
use Modules\Identity\Domain\Models\ApiKey;

/**
 * Résout une clé d'API depuis l'en-tête `Authorization`.
 *
 * Les deux formes d'authentification partagent le schéma `Bearer` ; le préfixe
 * `sk_` les distingue sans ambiguïté, ce qui évite de tenter une vérification
 * de signature JWT sur une clé opaque.
 *
 * @see docs/02-standards/security.md
 */
final class ApiKeyResolver
{
    public const PREFIX = 'sk_';

    /** @var array<int, ApiKeyContext|null> */
    private array $resolved = [];

    public function resolve(Request $request): ?ApiKeyContext
    {
        $id = spl_object_id($request);

        if (array_key_exists($id, $this->resolved)) {
            return $this->resolved[$id];
        }

        return $this->resolved[$id] = $this->build($request);
    }

    public function require(Request $request, string $scope): ApiKeyContext
    {
        $context = $this->resolve($request);

        if ($context === null) {
            throw new DomainException(
                'UNAUTHENTICATED',
                __('identity::messages.api_key_required'),
                401,
            );
        }

        if (! $context->key->hasScope($scope)) {
            throw DomainException::forbidden(
                'INSUFFICIENT_PERMISSIONS',
                __('identity::messages.api_key_scope_missing'),
            );
        }

        return $context;
    }

    public static function looksLikeApiKey(?string $token): bool
    {
        return $token !== null && str_starts_with($token, self::PREFIX);
    }

    private function build(Request $request): ?ApiKeyContext
    {
        $token = $request->bearerToken();

        if (! self::looksLikeApiKey($token)) {
            return null;
        }

        $key = ApiKey::query()->where('key_hash', ApiKey::hash($token))->first();

        // Clé inconnue et clé révoquée renvoient la même réponse : distinguer
        // permettrait de sonder quelles clés ont existé.
        if ($key === null || ! $key->isUsable()) {
            throw new DomainException(
                'API_KEY_INVALID',
                __('identity::messages.api_key_invalid'),
                401,
            );
        }

        // Repère les clés dormantes, qu'il faudra un jour révoquer.
        $key->forceFill(['last_used_at' => now()])->saveQuietly();

        return new ApiKeyContext($key);
    }
}
