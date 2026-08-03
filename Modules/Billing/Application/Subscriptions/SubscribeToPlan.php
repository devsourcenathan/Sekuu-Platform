<?php

declare(strict_types=1);

namespace Modules\Billing\Application\Subscriptions;

use App\Platform\Events\PublishesDomainEvents;
use App\Platform\Exceptions\DomainException;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Modules\Billing\Application\Invoicing\IssueInvoice;
use Modules\Billing\Domain\Models\Invoice;
use Modules\Billing\Domain\Models\Plan;
use Modules\Billing\Domain\Models\PlanPrice;
use Modules\Billing\Domain\Models\Subscription;
use Modules\Billing\Domain\SubscriptionStatus;

/**
 * Souscription.
 *
 * **Souscrire ne donne pas l'accès.** En l'absence d'essai, la souscription
 * produit une facture ; c'est le paiement qui ouvre le produit. Ouvrir d'abord
 * et facturer ensuite reviendrait à accorder un crédit à un inconnu.
 *
 * @see docs/03-services/billing/03-api.md
 */
final class SubscribeToPlan
{
    use PublishesDomainEvents;

    public function __construct(
        private readonly IssueInvoice $invoices,
        private readonly ActivateSubscription $activation,
    ) {}

    /**
     * @return array{subscription: Subscription, invoice: Invoice|null}
     */
    public function handle(
        string $organizationId,
        string $planKey,
        ?string $priceId = null,
        ?string $userId = null,
    ): array {
        $plan = $this->plan($planKey);
        $price = $this->price($plan, $priceId);

        $subscription = $this->create($organizationId, $plan, $price, $userId);

        $this->publish('billing.subscription.created', [
            'subscription_id' => $subscription->id,
            'plan_key' => $plan->key,
            'status' => $subscription->status->value,
        ], $organizationId);

        // L'essai ouvre l'accès sans paiement : c'est tout son objet.
        if ($subscription->status === SubscriptionStatus::Trialing) {
            $this->publish(
                'billing.subscription.activated',
                $this->activation->payload($subscription->load(['plan.products', 'price'])),
                $organizationId,
            );

            return ['subscription' => $subscription, 'invoice' => null];
        }

        $invoice = $this->invoices->handle(
            organizationId: $organizationId,
            lines: [[
                'description' => __('billing::messages.invoice_line_plan', [
                    'plan' => $plan->name,
                    'period' => $subscription->current_period_start->translatedFormat('F Y'),
                ]),
                'unit_amount' => $price->amount,
            ]],
            currency: $price->currency,
            subscription: $subscription,
            periodStart: $subscription->current_period_start->toDateTimeString(),
            periodEnd: $subscription->current_period_end->toDateTimeString(),
        );

        // Facture à zéro — plan gratuit, ou crédit couvrant tout : rien à
        // payer, donc l'accès s'ouvre immédiatement.
        if ($invoice->status === Invoice::PAID) {
            $this->activation->activate($subscription->fresh(['plan.products', 'price']));
        }

        return ['subscription' => $subscription->fresh(), 'invoice' => $invoice];
    }

    private function create(string $organizationId, Plan $plan, PlanPrice $price, ?string $userId): Subscription
    {
        $now = CarbonImmutable::now();

        $trialEnd = $plan->offersTrial() ? $now->addDays($plan->trial_days) : null;

        try {
            return DB::transaction(fn (): Subscription => Subscription::create([
                'organization_id' => $organizationId,
                'plan_id' => $plan->id,
                'plan_price_id' => $price->id,
                // `Pending` et non `Suspended` : un abonnement qui n'a jamais
                // été payé n'a jamais rien eu à perdre.
                'status' => $trialEnd !== null ? SubscriptionStatus::Trialing : SubscriptionStatus::Pending,
                'current_period_start' => $now,
                'current_period_end' => $trialEnd ?? $price->advance($now),
                'trial_ends_at' => $trialEnd,
                'created_by' => $userId,
            ]));
        } catch (QueryException $exception) {
            if (($exception->errorInfo[0] ?? null) !== '23505') {
                throw $exception;
            }

            // L'index partiel garantit un seul abonnement vivant par
            // organisation. Le conflit signifie qu'il en existe déjà un.
            throw DomainException::conflict(
                'SUBSCRIPTION_ALREADY_ACTIVE',
                __('billing::messages.subscription_already_active'),
            );
        }
    }

    private function plan(string $key): Plan
    {
        $plan = Plan::query()->with('products')->where('key', $key)->first();

        if ($plan === null) {
            throw DomainException::notFound('PLAN_NOT_FOUND', __('billing::messages.plan_not_found'));
        }

        if ($plan->isArchived()) {
            throw DomainException::conflict('PLAN_ARCHIVED', __('billing::messages.plan_archived'));
        }

        return $plan;
    }

    private function price(Plan $plan, ?string $priceId): PlanPrice
    {
        $query = $plan->prices()->active();

        $price = $priceId !== null
            ? $query->whereKey($priceId)->first()
            // Sans choix explicite, le tarif mensuel : c'est le plus petit
            // engagement, donc le défaut le moins présomptueux.
            : $query->where('interval', 'month')->first() ?? $query->first();

        if ($price === null) {
            throw DomainException::unprocessable(
                'CURRENCY_NOT_SUPPORTED',
                __('billing::messages.price_not_available'),
            );
        }

        return $price;
    }
}
