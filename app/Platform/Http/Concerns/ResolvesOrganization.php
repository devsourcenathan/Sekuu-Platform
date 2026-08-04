<?php

declare(strict_types=1);

namespace App\Platform\Http\Concerns;

use App\Platform\Contracts\RequestContext;
use App\Platform\Exceptions\DomainException;

/**
 * Organisation active et contrôle de rôle, pour les contrôleurs de tout module.
 *
 * Promu depuis `Modules/Billing` : un second module en aurait fait une copie,
 * et avec elle une copie de son entorse — la version d'origine importait
 * `JwtUserResolver`, l'infrastructure d'Identity plutôt qu'un contrat. Elle
 * passe désormais par `RequestContext`.
 *
 * @see docs/01-overview/architecture.md
 */
trait ResolvesOrganization
{
    protected function organizationId(): string
    {
        return $this->context()->organizationId()
            ?? throw DomainException::forbidden(
                'ORGANIZATION_REQUIRED',
                __('platform.organization_required'),
            );
    }

    protected function userId(): string
    {
        return $this->context()->userId();
    }

    /**
     * Certaines actions engagent l'organisation — financièrement ou
     * durablement — et ne sont pas des actions de membre ordinaire.
     *
     * @param  list<string>  $roles
     */
    protected function requireRole(array $roles, string $message): void
    {
        if (! $this->context()->hasAnyRole($roles)) {
            throw DomainException::forbidden('INSUFFICIENT_PERMISSIONS', $message);
        }
    }

    /**
     * @param  list<string>  $roles
     */
    protected function hasRole(array $roles): bool
    {
        return $this->context()->hasAnyRole($roles);
    }

    private function context(): RequestContext
    {
        return app(RequestContext::class);
    }
}
