<?php

declare(strict_types=1);

namespace Modules\Billing\Application\Payments;

use App\Platform\Events\PublishesDomainEvents;
use Modules\Billing\Domain\AttemptStatus;
use Modules\Billing\Domain\Models\PaymentAttempt;
use Modules\Billing\Domain\Models\PaymentIntent;
use Modules\Billing\Infrastructure\Providers\ChargeOutcome;
use Modules\Billing\Infrastructure\Providers\ProviderRegistry;

/**
 * Sondage des paiements en attente.
 *
 * **Obligatoire, jamais optionnel.** Un callback se perd, arrive deux fois, ou
 * arrive dans le désordre. S'il est la seule source d'information, un client
 * peut avoir été débité sans que la plateforme le sache — il a payé et n'a pas
 * son accès, la pire défaillance possible pour ce module.
 *
 * @see docs/04-decisions/adr-0007-mobile-money-prepaid-subscriptions.md
 */
final class ReconcilePayments
{
    use PublishesDomainEvents;

    public function __construct(
        private readonly ProviderRegistry $providers,
        private readonly SettlePayment $settlement,
    ) {}

    /**
     * @return array{polled: int, settled: int, unresolved: int}
     */
    public function handle(): array
    {
        $polled = 0;
        $settled = 0;

        $attempts = PaymentAttempt::query()
            ->whereIn('status', [AttemptStatus::Prompted->value, AttemptStatus::Processing->value])
            ->with('intent')
            ->orderBy('last_polled_at')
            ->limit(200)
            ->get();

        foreach ($attempts as $attempt) {
            if ($attempt->intent === null) {
                continue;
            }

            $polled++;

            $outcome = $this->poll($attempt);

            $attempt->forceFill([
                'last_polled_at' => now(),
                'poll_count' => $attempt->poll_count + 1,
            ])->save();

            $this->settlement->applyToAttempt($attempt, $outcome);

            if ($attempt->fresh()->status->isTerminal()) {
                $this->settlement->applyToIntent($attempt->intent->fresh(), $attempt->fresh());
                $settled++;
            }
        }

        return ['polled' => $polled, 'settled' => $settled, 'unresolved' => $this->expire()];
    }

    private function poll(PaymentAttempt $attempt): ChargeOutcome
    {
        try {
            return $this->providers->byName($attempt->provider)->poll($attempt);
        } catch (\Throwable $exception) {
            // Un agrégateur injoignable ne change rien à l'état de la
            // tentative : on réessaiera. Le marquer en échec ferait perdre un
            // paiement peut-être encaissé.
            return ChargeOutcome::unknown($exception->getMessage(), $attempt->provider_ref);
        }
    }

    /**
     * Intentions dépassées sans réponse.
     *
     * `expired` signifie **on ne sait pas**, et non « cela a échoué ». Le
     * client a peut-être été débité : ces intentions partent au rapprochement
     * manuel, jamais à une nouvelle tentative automatique.
     */
    private function expire(): int
    {
        $stale = PaymentIntent::query()
            ->whereIn('status', [PaymentIntent::PENDING, PaymentIntent::PROCESSING])
            ->where('expires_at', '<', now())
            ->get();

        foreach ($stale as $intent) {
            $intent->forceFill([
                'status' => PaymentIntent::EXPIRED,
                'failure_code' => 'PAYMENT_UNRESOLVED',
                'failure_reason' => __('billing::messages.payment_unresolved'),
            ])->save();

            $intent->attempts()
                ->whereIn('status', [AttemptStatus::Prompted->value, AttemptStatus::Processing->value])
                ->update(['status' => AttemptStatus::Expired->value, 'settled_at' => now()]);

            // Sans destinataire produit : cet événement alerte l'équipe. Le
            // taire serait laisser un client payé sans service.
            $this->publish('billing.payment.unresolved', [
                'payment_intent_id' => $intent->id,
                'invoice_id' => $intent->invoice_id,
                'amount' => $intent->amount,
                'currency' => $intent->currency,
                'provider_refs' => $intent->attempts()->pluck('provider_ref')->filter()->values()->all(),
            ], $intent->organization_id);
        }

        return $stale->count();
    }
}
