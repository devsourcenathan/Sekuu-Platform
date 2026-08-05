<?php

declare(strict_types=1);

namespace Modules\Billing\Application\Plans;

use Modules\Billing\Domain\Models\Plan;
use Modules\Billing\Domain\Models\Subscription;

/**
 * Reporter les limites d'un plan sur les abonnements qui en dépendent.
 *
 * ## L'asymétrie est la décision
 *
 * Une hausse est reportée **immédiatement**. Une baisse attend le
 * renouvellement. Ce n'est pas une commodité : cela dit que **la plateforme
 * peut être plus généreuse que promis, jamais moins**.
 *
 * Conséquence utile : un opérateur qui se trompe en haussant fait un cadeau ;
 * le même qui se trompe en baissant ne casse rien avant le renouvellement, et a
 * le temps de se reprendre.
 *
 * @see docs/04-decisions/adr-0019-granted-limits.md
 */
final class GrantLimits
{
    /**
     * À l'ouverture d'une période : la copie est intégrale.
     *
     * C'est le seul moment où une baisse prend effet — le client entame une
     * période qu'il vient de payer au tarif et aux conditions du jour.
     */
    public function atPeriodStart(Subscription $subscription): void
    {
        $subscription->forceFill([
            'granted_limits' => (array) ($subscription->plan?->limits ?? []),
            'limits_granted_at' => now(),
        ])->save();
    }

    /**
     * Après une modification du catalogue : seules les hausses passent.
     *
     * @return array{applied_now: list<string>, applied_at_renewal: list<string>, subscriptions: int}
     */
    public function afterPlanChange(Plan $plan): array
    {
        $catalogue = (array) ($plan->limits ?? []);
        $maintenant = [];
        $auRenouvellement = [];
        $touchees = 0;

        foreach (Subscription::query()->where('plan_id', $plan->id)->alive()->cursor() as $subscription) {
            $accordees = (array) ($subscription->granted_limits ?? []);
            $apres = $accordees;

            foreach ($catalogue as $cle => $valeur) {
                if ($this->isRaise($accordees, $cle, $valeur)) {
                    $apres[$cle] = $valeur;
                    $maintenant[$cle] = true;

                    continue;
                }

                if (! array_key_exists($cle, $accordees) || $accordees[$cle] !== $valeur) {
                    $auRenouvellement[$cle] = true;
                }
            }

            /*
             * Une clé **retirée** du catalogue ferme une ressource : c'est une
             * baisse, elle attend. On ne touche donc pas aux clés de la copie
             * qui ont disparu du plan.
             */
            foreach (array_diff_key($accordees, $catalogue) as $cle => $_) {
                $auRenouvellement[$cle] = true;
            }

            if ($apres !== $accordees) {
                $subscription->forceFill([
                    'granted_limits' => $apres,
                    'limits_granted_at' => now(),
                ])->save();
            }

            $touchees++;
        }

        return [
            'applied_now' => array_keys($maintenant),
            'applied_at_renewal' => array_keys(array_diff_key($auRenouvellement, $maintenant)),
            'subscriptions' => $touchees,
        ];
    }

    /**
     * Une hausse, au sens de la décision : ce qui **n'enlève rien**.
     *
     * Trois cas, et le second est celui qu'on oublie :
     *
     *  - une clé qui apparaît ouvre une ressource — c'est une hausse ;
     *  - `null` signifie **illimité**, donc supérieur à toute valeur, et un
     *    passage de `null` à un nombre est une baisse même si le nombre est
     *    grand ;
     *  - une valeur qui monte est une hausse.
     */
    private function isRaise(array $accordees, string $cle, mixed $valeur): bool
    {
        if (! array_key_exists($cle, $accordees)) {
            return true;
        }

        $actuelle = $accordees[$cle];

        if ($actuelle === null) {
            return false;
        }

        if ($valeur === null) {
            return true;
        }

        return (int) $valeur > (int) $actuelle;
    }
}
