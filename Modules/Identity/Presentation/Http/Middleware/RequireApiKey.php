<?php

declare(strict_types=1);

namespace Modules\Identity\Presentation\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Identity\Infrastructure\Auth\ApiKeyResolver;
use Symfony\Component\HttpFoundation\Response;

/**
 * Exige une clé d'API portant un scope donné.
 *
 * S'utilise sur les routes qui agissent au nom de la plateforme plutôt qu'au
 * nom d'une personne — déclencher un envoi, notamment.
 */
final class RequireApiKey
{
    public function __construct(private readonly ApiKeyResolver $resolver) {}

    public function handle(Request $request, Closure $next, string $scope): Response
    {
        $this->resolver->require($request, $scope);

        return $next($request);
    }
}
