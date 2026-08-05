<?php

declare(strict_types=1);

namespace Modules\Billing\Application\Subscriptions;

use App\Platform\Events\PublishesDomainEvents;
use Carbon\CarbonImmutable;
use Modules\Billing\Application\Notifications\AddressesTheOrganization;
use Modules\Billing\Domain\Models\Invoice;
use Modules\Billing\Domain\Models\Plan;
use Modules\Billing\Domain\Models\Subscription;
use Modules\Billing\Domain\SubscriptionStatus;

/**
 * Ouverture ou prolongation d'une période payée.
 *
 * C'est le seul endroit d'où part `billing.subscription.activated` — l'unique
 * événement qui ouvre l'accès à un produit. Identity l'applique ; Billing ne
 * touche jamais `organization_products` directement.
 *
 * @see docs/03-services/billing/04-events.md
 */
final class ActivateSubscription
{
    use AddressesTheOrganization;
    use PublishesDomainEvents;

    public function fromInvoice(Invoice $invoice): ?Subscription
    {
        $subscription = Subscription::query()->with(['plan.products', 'price'])->find($invoice->subscription_id);

        if ($subscription === null) {
            return null;
        }

        $renewal = $subscription->status !== SubscriptionStatus::Trialing
            && $subscription->current_period_end->isPast();

        return $this->activate($subscription, $renewal);
    }

    public function activate(Subscription $subscription, bool $renewal = false): Subscription
    {
        $price = $subscription->price;

        // La période démarre à la fin de la précédente quand celle-ci n'est pas
        // encore échue : un renouvellement anticipé **ajoute** du temps, il n'en
        // fait pas perdre.
        $start = $subscription->current_period_end->isFuture() && ! $renewal
            ? $subscription->current_period_end
            : CarbonImmutable::now();

        // Une descente en gamme différée s'applique ici, au renouvellement :
        // la période précédente était payée, l'écourter aurait obligé à
        // rembourser.
        if ($subscription->pending_plan_id !== null) {
            $subscription->plan_id = $subscription->pending_plan_id;
            $subscription->plan_price_id = $subscription->pending_plan_price_id;
            $subscription->pending_plan_id = null;
            $subscription->pending_plan_price_id = null;
            $subscription->load(['plan.products', 'price']);
            $price = $subscription->price;
        }

        $subscription->forceFill([
            'plan_id' => $subscription->plan_id,
            'plan_price_id' => $subscription->plan_price_id,
            'status' => SubscriptionStatus::Active,
            'current_period_start' => $start,
            'current_period_end' => $price->advance($start),
            'grace_ends_at' => null,
            'suspended_at' => null,

            /*
             * La copie est refaite à chaque ouverture de période.
             *
             * C'est le **seul** moment où une baisse du catalogue prend effet :
             * le client entame une période qu'il vient de payer aux conditions
             * du jour. Entre deux périodes, il garde ce qui lui a été promis —
             * voir ADR-0019.
             */
            'granted_limits' => (array) ($subscription->plan?->limits ?? []),
            'limits_granted_at' => now(),
        ])->save();

        $subscription->refresh()->load(['plan.products', 'price']);

        $this->publish(
            $renewal ? 'billing.subscription.renewed' : 'billing.subscription.activated',
            [
                ...$this->payload($subscription),
                ...$this->addressed($subscription->organization_id, [
                    'plan_name' => $subscription->plan->name,
                    'period_end' => $subscription->current_period_end->translatedFormat('d F Y'),
                ]),
            ],
            $subscription->organization_id,
        );

        return $subscription;
    }

    /**
     * Les produits et les limites sont **portés par l'événement**, jamais
     * rechargés par le consommateur : Identity n'a ainsi aucune table de
     * Billing à lire, et l'événement reste explicable des mois plus tard — il
     * dit ce qui a été accordé, pas ce que le plan contient aujourd'hui.
     *
     * @return array<string, mixed>
     */
    public function payload(Subscription $subscription): array
    {
        /** @var Plan $plan */
        $plan = $subscription->plan;

        return [
            'subscription_id' => $subscription->id,
            'plan_key' => $plan->key,
            'plan_name' => $plan->name,
            'products' => $plan->products->pluck('product_id')->all(),
            'limits' => $plan->limits ?? [],
            'status' => $subscription->status->value,
            'current_period_start' => $subscription->current_period_start->toIso8601ZuluString(),
            'current_period_end' => $subscription->current_period_end->toIso8601ZuluString(),
        ];
    }
}
