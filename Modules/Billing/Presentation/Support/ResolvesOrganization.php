<?php

declare(strict_types=1);

namespace Modules\Billing\Presentation\Support;

use App\Platform\Exceptions\DomainException;
use Illuminate\Http\Request;
use Modules\Identity\Domain\AuthenticatedContext;
use Modules\Identity\Infrastructure\Auth\JwtUserResolver;

/**
 * Contexte d'organisation et contrôle de rôle.
 *
 * **Engager une dépense n'est pas une action de membre ordinaire.** Souscrire,
 * monter en gamme ou déclencher un paiement exige `Owner` ou `Admin` : ce sont
 * des actes qui engagent l'organisation financièrement.
 *
 * Consulter reste ouvert à tout membre — un utilisateur doit pouvoir comprendre
 * pourquoi une fonctionnalité lui est refusée sans demander à son patron.
 *
 * @see docs/03-services/billing/03-api.md
 */
trait ResolvesOrganization
{
    /** Rôles habilités à engager l'organisation. */
    private const BILLING_ROLES = ['owner', 'admin'];

    protected function organizationId(Request $request): string
    {
        $context = app(JwtUserResolver::class)->require($request);

        return $context->token->organizationId
            ?? throw DomainException::forbidden(
                'ORGANIZATION_REQUIRED',
                __('platform.organization_required'),
            );
    }

    protected function requireBillingRole(Request $request): AuthenticatedContext
    {
        $context = app(JwtUserResolver::class)->require($request);

        foreach (self::BILLING_ROLES as $role) {
            if ($context->token->hasRole($role)) {
                return $context;
            }
        }

        throw DomainException::forbidden(
            'INSUFFICIENT_PERMISSIONS',
            __('billing::messages.billing_role_required'),
        );
    }
}
