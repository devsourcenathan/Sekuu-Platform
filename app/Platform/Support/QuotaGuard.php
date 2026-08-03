<?php

declare(strict_types=1);

namespace App\Platform\Support;

use App\Platform\Contracts\BillingContract;
use App\Platform\Exceptions\DomainException;

/**
 * Refus d'une écriture qui dépasserait le quota du plan.
 *
 * Le **comptage** appartient à chaque module — lui seul sait compter sa
 * ressource. Seule la partie identique est ici : lire la limite, comparer,
 * refuser.
 *
 * ## Ce que ce garde-fou ne fait pas
 *
 * Il **n'est pas un contrôle d'accès**. Une organisation sans abonnement, ou
 * dont le plan ne mentionne pas la ressource, n'est pas bloquée ici. Le faire
 * dupliquerait le rôle d'`organization_products` côté Identity — et surtout,
 * cela fermerait toute organisation créée avant qu'un abonnement n'existe,
 * y compris pendant l'inscription.
 *
 * Un quota borne un usage **autorisé**. Il ne décide pas de l'autorisation.
 *
 * @see docs/03-services/billing/01-overview.md
 */
final class QuotaGuard
{
    public function __construct(private readonly BillingContract $billing) {}

    /**
     * @param  int  $current  usage constaté, compté par l'appelant
     * @param  string  $message  message traduit, propre au module appelant
     */
    public function assertAllows(
        ?string $organizationId,
        string $key,
        int $current,
        string $message,
    ): void {
        if ($organizationId === null) {
            return;
        }

        $limit = $this->billing->limit($organizationId, $key);

        // Non couvert ou illimité : rien à plafonner. Voir plus haut pourquoi
        // « non couvert » n'est pas un refus.
        if (! $limit->covered || $limit->isUnlimited()) {
            return;
        }

        if ($limit->allows($current)) {
            return;
        }

        throw new DomainException('QUOTA_EXCEEDED', $message, 429, [
            'limit' => $key,
            'current' => $current,
            'allowed' => $limit->value,
        ]);
    }

    public function remaining(?string $organizationId, string $key, int $current): ?int
    {
        if ($organizationId === null) {
            return null;
        }

        $limit = $this->billing->limit($organizationId, $key);

        if (! $limit->covered || $limit->isUnlimited()) {
            return null;
        }

        return max(0, (int) $limit->value - $current);
    }
}
