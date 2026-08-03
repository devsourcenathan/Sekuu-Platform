<?php

declare(strict_types=1);

namespace Modules\Billing\Application\Subscriptions;

use App\Platform\Events\PublishesDomainEvents;
use App\Platform\Exceptions\DomainException;
use Modules\Billing\Application\Invoicing\IssueInvoice;
use Modules\Billing\Application\Ledger\CreditLedger;
use Modules\Billing\Domain\Models\Invoice;
use Modules\Billing\Domain\Models\Plan;
use Modules\Billing\Domain\Models\PlanPrice;
use Modules\Billing\Domain\Models\Subscription;

/**
 * Changement de plan.
 *
 * Le sens commande le comportement, et la réponse le dit explicitement plutôt
 * que de laisser deviner :
 *
 *  - **montée en gamme** : immédiate, le reliquat payé est imputé en crédit ;
 *  - **descente en gamme** : différée au terme — la période en cours est payée,
 *    l'écourter obligerait à rembourser, ce que le Mobile Money rend lent et
 *    coûteux.
 *
 * @see docs/03-services/billing/03-api.md
 */
final class ChangePlan
{
    use PublishesDomainEvents;

    public function __construct(
        private readonly IssueInvoice $invoices,
        private readonly CreditLedger $credit,
    ) {}

    /**
     * @param  array<string, int>  $usage  usage courant, pour refuser une descente destructrice
     * @return array{direction: string, effective: string, credit_applied: int, invoice: Invoice|null}
     */
    public function handle(Subscription $subscription, Plan $target, PlanPrice $price, array $usage = []): array
    {
        if ($target->id === $subscription->plan_id && $price->id === $subscription->plan_price_id) {
            throw DomainException::conflict(
                'RESOURCE_CONFLICT',
                __('billing::messages.already_on_plan'),
            );
        }

        $current = $subscription->price;
        $upgrade = $this->monthlyValue($price) > $this->monthlyValue($current);

        return $upgrade
            ? $this->upgrade($subscription, $target, $price)
            : $this->downgrade($subscription, $target, $price, $usage);
    }

    /**
     * @return array{direction: string, effective: string, credit_applied: int, invoice: Invoice}
     */
    private function upgrade(Subscription $subscription, Plan $target, PlanPrice $price): array
    {
        // Le reliquat non consommé devient un crédit, jamais un remboursement :
        // un remboursement Mobile Money est lent, coûteux, souvent manuel.
        $unused = $subscription->price->money()->multipliedBy($subscription->unusedRatio());

        if ($unused->isPositive()) {
            $this->credit->credit(
                $subscription->organization_id,
                $unused,
                __('billing::messages.proration_credit', ['plan' => $subscription->plan->name]),
            );
        }

        $subscription->forceFill([
            'plan_id' => $target->id,
            'plan_price_id' => $price->id,
            'pending_plan_id' => null,
            'pending_plan_price_id' => null,
        ])->save();

        // Le crédit est imputé par l'émission de la facture, et apparaît comme
        // une **ligne** : un client doit pouvoir vérifier son total à la main.
        $invoice = $this->invoices->handle(
            organizationId: $subscription->organization_id,
            lines: [[
                'description' => __('billing::messages.invoice_line_plan', [
                    'plan' => $target->name,
                    'period' => now()->translatedFormat('F Y'),
                ]),
                'unit_amount' => $price->amount,
            ]],
            currency: $price->currency,
            subscription: $subscription,
        );

        $this->publish('billing.subscription.changed', [
            'subscription_id' => $subscription->id,
            'plan_key' => $target->key,
            'direction' => 'upgrade',
            'effective' => 'immediate',
        ], $subscription->organization_id);

        return [
            'direction' => 'upgrade',
            'effective' => 'immediate',
            'credit_applied' => $invoice->credit_applied,
            'invoice' => $invoice,
        ];
    }

    /**
     * @param  array<string, int>  $usage
     * @return array{direction: string, effective: string, credit_applied: int, invoice: null}
     */
    private function downgrade(Subscription $subscription, Plan $target, PlanPrice $price, array $usage): array
    {
        $this->assertUsageFits($target, $usage);

        $subscription->forceFill([
            'pending_plan_id' => $target->id,
            'pending_plan_price_id' => $price->id,
        ])->save();

        $this->publish('billing.subscription.changed', [
            'subscription_id' => $subscription->id,
            'plan_key' => $target->key,
            'direction' => 'downgrade',
            'effective' => 'period_end',
            'effective_at' => $subscription->current_period_end->toIso8601ZuluString(),
        ], $subscription->organization_id);

        return [
            'direction' => 'downgrade',
            'effective' => 'period_end',
            'credit_applied' => 0,
            'invoice' => null,
        ];
    }

    /**
     * Refuser plutôt qu'accepter puis appliquer.
     *
     * Appliquer signifierait supprimer des workspaces du client. Aucun
     * formulaire de facturation ne devrait pouvoir détruire des données.
     *
     * @param  array<string, int>  $usage
     */
    private function assertUsageFits(Plan $target, array $usage): void
    {
        $violations = [];

        foreach ($usage as $key => $current) {
            $allowed = $target->limit($key);

            // `false` = non couvert par ce plan, `null` = illimité.
            if ($allowed === null) {
                continue;
            }

            if ($allowed === false || $current > $allowed) {
                $violations[] = [
                    'limit' => $key,
                    'current' => $current,
                    'allowed' => $allowed === false ? 0 : $allowed,
                ];
            }
        }

        if ($violations !== []) {
            throw new DomainException(
                'DOWNGRADE_NOT_ALLOWED',
                __('billing::messages.downgrade_not_allowed'),
                409,
                $violations,
            );
        }
    }

    /**
     * Compare deux tarifs sur une base commune : un annuel à 450 000 XAF est
     * une montée en gamme par rapport à un mensuel à 9 000, pas l'inverse.
     */
    private function monthlyValue(PlanPrice $price): float
    {
        $months = $price->interval === 'year' ? 12 * $price->interval_count : $price->interval_count;

        return $price->amount / max(1, $months);
    }
}
