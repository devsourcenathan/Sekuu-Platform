<?php

declare(strict_types=1);

namespace Modules\Billing\Infrastructure\Contracts;

use App\Platform\Contracts\BillingContract;
use App\Platform\Contracts\PlanLimit;
use Modules\Billing\Domain\Models\Subscription;

/**
 * Implémentation locale du contrat de Billing.
 *
 * @see docs/03-services/billing/01-overview.md
 */
final class BillingGateway implements BillingContract
{
    /**
     * Mémoïsation par requête.
     *
     * Un quota est consulté à chaque écriture — invitation, workspace, envoi.
     * Sans cache, un envoi groupé de cent messages produirait cent lectures
     * identiques. Le cache est porté par l'instance, jamais par le conteneur :
     * une limite mise en cache d'une requête à l'autre survivrait à un
     * changement de plan.
     *
     * @var array<string, PlanLimit>
     */
    private array $resolved = [];

    public function limit(string $organizationId, string $key): PlanLimit
    {
        return $this->resolved[$organizationId.':'.$key] ??= $this->resolve($organizationId, $key);
    }

    private function resolve(string $organizationId, string $key): PlanLimit
    {
        $subscription = Subscription::query()
            ->where('organization_id', $organizationId)
            ->alive()
            ->with('plan')
            ->first();

        // Sans abonnement vivant, il n'y a rien à plafonner : l'accès lui-même
        // est fermé, et c'est Identity qui l'applique.
        if ($subscription === null || $subscription->plan === null) {
            return PlanLimit::noSubscription();
        }

        $limits = $subscription->plan->limits ?? [];

        // Clé absente = la ressource n'est pas couverte par ce plan. Valeur
        // nulle = illimitée. La distinction est portée jusqu'à l'appelant.
        if (! array_key_exists($key, $limits)) {
            return PlanLimit::notCovered();
        }

        $value = $limits[$key];

        return $value === null ? PlanLimit::unlimited() : PlanLimit::of((int) $value);
    }
}
