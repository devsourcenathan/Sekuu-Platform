<?php

declare(strict_types=1);

namespace Modules\Identity\Presentation\Http\Middleware;

use App\Platform\Exceptions\DomainException;
use Closure;
use Illuminate\Http\Request;
use Modules\Identity\Infrastructure\Auth\JwtUserResolver;
use Symfony\Component\HttpFoundation\Response;

/**
 * Exige une permission globale, portée par le claim `scopes` du token.
 *
 * Ne s'applique **jamais** aux permissions métier : celles-ci appartiennent aux
 * produits et ne transitent pas par le token.
 *
 * @see docs/04-decisions/adr-0003-two-level-roles.md
 */
final class RequireScope
{
    public function __construct(private readonly JwtUserResolver $resolver) {}

    public function handle(Request $request, Closure $next, string ...$scopes): Response
    {
        $token = $this->resolver->require($request)->token;

        foreach ($scopes as $scope) {
            if ($token->hasScope($scope)) {
                return $next($request);
            }
        }

        throw DomainException::forbidden(
            'INSUFFICIENT_PERMISSIONS',
            __('identity::messages.role_insufficient'),
        );
    }
}
