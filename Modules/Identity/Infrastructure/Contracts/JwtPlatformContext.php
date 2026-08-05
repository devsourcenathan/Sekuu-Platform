<?php

declare(strict_types=1);

namespace Modules\Identity\Infrastructure\Contracts;

use App\Platform\Contracts\PlatformContext;
use Illuminate\Http\Request;
use Modules\Identity\Domain\Models\PlatformOperator;
use Modules\Identity\Infrastructure\Auth\JwtUserResolver;
use Throwable;

/**
 * Implémentation locale de `PlatformContext`.
 *
 * ## L'habilitation n'est pas dans le jeton
 *
 * Elle est relue **en base à chaque requête**, contrairement aux rôles
 * d'organisation qui voyagent dans l'access token.
 *
 * C'est délibéré, et c'est le seul endroit du système où l'on accepte cette
 * lecture supplémentaire. Un jeton vit quinze minutes ; une habilitation
 * d'opérateur révoquée doit cesser d'agir **immédiatement**, pas au
 * rafraîchissement suivant. Sur un pouvoir qui touche la tarification et les
 * données de tous les clients, un quart d'heure de latence est un quart d'heure
 * de trop.
 *
 * @see docs/04-decisions/adr-0018-platform-operator.md
 */
final class JwtPlatformContext implements PlatformContext
{
    private ?PlatformOperator $operator = null;

    private bool $resolved = false;

    public function __construct(
        private readonly JwtUserResolver $resolver,
        private readonly Request $request,
    ) {}

    public function may(string $permission): bool
    {
        return $this->operator()?->may($permission) ?? false;
    }

    public function operatorId(): ?string
    {
        return $this->operator()?->id !== null ? (string) $this->operator()->id : null;
    }

    private function operator(): ?PlatformOperator
    {
        if ($this->resolved) {
            return $this->operator;
        }

        $this->resolved = true;

        try {
            $userId = $this->resolver->require($this->request)->token->userId;
        } catch (Throwable) {
            // Non authentifié : pas d'opérateur, et surtout pas d'exception —
            // `may()` doit pouvoir être appelée n'importe où et répondre non.
            return $this->operator = null;
        }

        return $this->operator = PlatformOperator::query()
            ->where('user_id', $userId)
            ->whereNull('revoked_at')
            ->first();
    }
}
