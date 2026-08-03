<?php

declare(strict_types=1);

namespace Modules\Billing\Application\Subscriptions;

use App\Platform\Events\PublishesDomainEvents;
use Modules\Billing\Domain\Models\Subscription;
use Modules\Billing\Domain\SubscriptionStatus;

/**
 * Avancement quotidien du cycle de vie.
 *
 * **Idempotente par construction** : la relancer deux fois le même jour ne
 * raccourcit pas une grâce de deux jours. C'est la raison d'être de
 * `grace_ends_at`, une date absolue plutôt qu'un compteur décrémenté.
 *
 * @see docs/04-decisions/adr-0007-mobile-money-prepaid-subscriptions.md
 */
final class AdvanceLifecycle
{
    use PublishesDomainEvents;

    public function __construct(private readonly ActivateSubscription $activation) {}

    /**
     * @return array{grace: int, suspended: int, expired: int, reminded: int}
     */
    public function handle(): array
    {
        return [
            'grace' => $this->startGrace(),
            'suspended' => $this->suspend() + $this->abandonUnpaid(),
            'expired' => $this->expire(),
            'reminded' => $this->remind(),
        ];
    }

    /**
     * Souscriptions jamais payées.
     *
     * Un abonnement `pending` occupe l'unique place de l'organisation. Sans
     * cette purge, quelqu'un qui souscrit puis renonce reste bloqué : il ne
     * peut plus souscrire, y compris à un autre plan.
     */
    private function abandonUnpaid(): int
    {
        $days = (int) config('billing.grace_days', 7);

        $abandoned = Subscription::query()
            ->where('status', SubscriptionStatus::Pending->value)
            ->where('created_at', '<=', now()->subDays($days))
            ->get();

        foreach ($abandoned as $subscription) {
            $subscription->forceFill([
                'status' => SubscriptionStatus::Cancelled,
                'suspended_at' => now(),
                'cancellation_reason' => 'never_paid',
            ])->save();

            // Pas de `suspended` publié : rien n'a jamais été ouvert, donc rien
            // n'est fermé. Annoncer une perte d'accès inexistante serait faux.
        }

        return $abandoned->count();
    }

    /**
     * Fin de période sans paiement. L'accès reste **ouvert**.
     *
     * Sans grâce, une clinique découvre un lundi matin qu'elle ne peut plus
     * ouvrir son agenda. Sept jours de service non payé coûtent moins qu'un
     * client perdu.
     */
    private function startGrace(): int
    {
        $due = Subscription::query()
            ->whereIn('status', [SubscriptionStatus::Active->value, SubscriptionStatus::Trialing->value])
            ->where('current_period_end', '<=', now())
            ->get();

        foreach ($due as $subscription) {
            // Une résiliation demandée se traduit par une suspension directe :
            // le client a dit qu'il ne paierait plus, lui offrir une grâce
            // serait lui envoyer des relances qu'il n'a pas demandées.
            if ($subscription->cancel_at_period_end) {
                $this->suspendOne($subscription, cancelled: true);

                continue;
            }

            $subscription->forceFill([
                'status' => SubscriptionStatus::Grace,
                'grace_ends_at' => now()->addDays((int) config('billing.grace_days', 7)),
            ])->save();

            $this->publish('billing.subscription.grace_started', [
                'subscription_id' => $subscription->id,
                'grace_ends_at' => $subscription->fresh()->grace_ends_at?->toIso8601ZuluString(),
            ], $subscription->organization_id);
        }

        return $due->count();
    }

    private function suspend(): int
    {
        $expired = Subscription::query()
            ->where('status', SubscriptionStatus::Grace->value)
            ->where('grace_ends_at', '<=', now())
            ->get();

        foreach ($expired as $subscription) {
            $this->suspendOne($subscription);
        }

        return $expired->count();
    }

    /**
     * Fermer l'accès ne détruit rien. Les données appartiennent au client, pas
     * au contrat : leur suppression relève de la rétention de chaque produit et
     * d'une décision explicite, jamais d'un défaut de paiement.
     */
    private function suspendOne(Subscription $subscription, bool $cancelled = false): void
    {
        $subscription->forceFill([
            'status' => $cancelled ? SubscriptionStatus::Cancelled : SubscriptionStatus::Suspended,
            'suspended_at' => now(),
            'grace_ends_at' => null,
        ])->save();

        $this->publish('billing.subscription.suspended', [
            'subscription_id' => $subscription->id,
            'reason' => $cancelled ? 'cancelled' : 'unpaid',
        ], $subscription->organization_id);
    }

    private function expire(): int
    {
        $days = (int) config('billing.expire_after_days', 90);

        $stale = Subscription::query()
            ->whereIn('status', [SubscriptionStatus::Suspended->value, SubscriptionStatus::Cancelled->value])
            ->where('suspended_at', '<=', now()->subDays($days))
            ->get();

        foreach ($stale as $subscription) {
            $subscription->forceFill(['status' => SubscriptionStatus::Expired])->save();

            $this->publish('billing.subscription.expired', [
                'subscription_id' => $subscription->id,
            ], $subscription->organization_id);
        }

        return $stale->count();
    }

    /**
     * Rappels d'échéance.
     *
     * Le modèle prépayé les rend indispensables : c'est la seule chose que la
     * plateforme puisse faire pour être payée, puisqu'elle ne peut pas prélever.
     */
    private function remind(): int
    {
        $count = 0;

        foreach ((array) config('billing.reminder_days', [7, 3, 1]) as $days) {
            $subscriptions = Subscription::query()
                ->where('status', SubscriptionStatus::Active->value)
                ->where('cancel_at_period_end', false)
                ->whereBetween('current_period_end', [
                    now()->addDays((int) $days)->startOfDay(),
                    now()->addDays((int) $days)->endOfDay(),
                ])
                ->with('plan')
                ->get();

            foreach ($subscriptions as $subscription) {
                $this->publish('billing.subscription.expiring', [
                    'subscription_id' => $subscription->id,
                    'plan_name' => $subscription->plan->name,
                    'days_remaining' => (int) $days,
                    'current_period_end' => $subscription->current_period_end->toIso8601ZuluString(),
                ], $subscription->organization_id);

                $count++;
            }
        }

        return $count;
    }
}
