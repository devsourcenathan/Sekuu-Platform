<?php

declare(strict_types=1);

namespace Modules\Billing\Presentation\Http\Controllers\Concerns;

use App\Platform\Http\Concerns\ResolvesOrganization;

/**
 * Rôles habilités à engager financièrement l'organisation.
 *
 * La liste est propre à Billing : « engager une dépense n'est pas une action de
 * membre ordinaire » est vrai d'un abonnement d'entreprise, et absurde d'un
 * apprenant qui achète sa propre formation. Elle n'a donc pas sa place dans le
 * trait partagé.
 */
trait EngagesTheOrganization
{
    use ResolvesOrganization;

    /** @var list<string> */
    private const ROLES_FACTURATION = ['owner', 'admin'];

    protected function requireBillingRole(): void
    {
        $this->requireRole(self::ROLES_FACTURATION, __('billing::messages.billing_role_required'));
    }

    protected function hasBillingRole(): bool
    {
        return $this->hasRole(self::ROLES_FACTURATION);
    }
}
