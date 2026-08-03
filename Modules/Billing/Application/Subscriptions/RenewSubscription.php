<?php

declare(strict_types=1);

namespace Modules\Billing\Application\Subscriptions;

use App\Platform\Exceptions\DomainException;
use Modules\Billing\Application\Invoicing\IssueInvoice;
use Modules\Billing\Domain\Models\Invoice;
use Modules\Billing\Domain\Models\Subscription;

/**
 * Renouvellement — **l'acte volontaire** qui remplace la reconduction tacite.
 *
 * Il n'existe pas de prélèvement automatique en Mobile Money : la plateforme
 * n'en a pas le moyen technique. Le renouvellement est donc une action du
 * client, pas un effet de bord du temps qui passe.
 *
 * @see docs/04-decisions/adr-0007-mobile-money-prepaid-subscriptions.md
 */
final class RenewSubscription
{
    public function __construct(
        private readonly IssueInvoice $invoices,
        private readonly ActivateSubscription $activation,
    ) {}

    public function handle(Subscription $subscription): Invoice
    {
        // Un abonnement résilié ou expiré ne se renouvelle pas : on souscrit à
        // nouveau. Prolonger un contrat éteint masquerait la rupture.
        if (! $subscription->status->isAlive() && $subscription->suspended_at === null) {
            throw DomainException::conflict(
                'SUBSCRIPTION_NOT_RENEWABLE',
                __('billing::messages.subscription_not_renewable'),
            );
        }

        $existing = $subscription->invoices()
            ->where('status', Invoice::OPEN)
            ->whereNotNull('period_end')
            ->where('period_end', '>', $subscription->current_period_end)
            ->first();

        // Une facture de renouvellement déjà ouverte est renvoyée telle quelle :
        // en émettre une seconde produirait deux documents pour une seule
        // période, et deux numéros consommés.
        if ($existing !== null) {
            return $existing;
        }

        $price = $subscription->pending_plan_price_id !== null
            ? $subscription->pendingPrice()
            : $subscription->price;

        $plan = $subscription->pending_plan_id !== null
            ? $subscription->pendingPlan
            : $subscription->plan;

        $start = $subscription->current_period_end->isFuture()
            ? $subscription->current_period_end
            : now()->toImmutable();

        return $this->invoices->handle(
            organizationId: $subscription->organization_id,
            lines: [[
                'description' => __('billing::messages.invoice_line_plan', [
                    'plan' => $plan->name,
                    'period' => $start->translatedFormat('F Y'),
                ]),
                'unit_amount' => $price->amount,
            ]],
            currency: $price->currency,
            subscription: $subscription,
            periodStart: $start->toDateTimeString(),
            periodEnd: $price->advance($start)->toDateTimeString(),
        );
    }
}
