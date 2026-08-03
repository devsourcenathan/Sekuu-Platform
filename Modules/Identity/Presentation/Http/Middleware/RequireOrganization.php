<?php

declare(strict_types=1);

namespace Modules\Identity\Presentation\Http\Middleware;

use App\Platform\Exceptions\DomainException;
use Closure;
use Illuminate\Http\Request;
use Modules\Identity\Infrastructure\Auth\JwtUserResolver;
use Symfony\Component\HttpFoundation\Response;

/**
 * Exige un token portant une organisation active.
 *
 * Un token sans claim `org` ne donne accès qu'aux routes de profil.
 *
 * @see docs/02-standards/security.md
 */
final class RequireOrganization
{
    public function __construct(private readonly JwtUserResolver $resolver) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->resolver->require($request)->token->hasOrganization()) {
            throw DomainException::forbidden(
                'ORGANIZATION_REQUIRED',
                __('platform.organization_required'),
            );
        }

        return $next($request);
    }
}
