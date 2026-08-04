<?php

declare(strict_types=1);

namespace Modules\Payments\Application\Payments;

use App\Platform\Support\Money;
use Modules\Payments\Domain\Models\PaymentAttempt;
use Modules\Payments\Domain\Models\PaymentTransaction;

/**
 * Registre de caisse.
 *
 * Ne connaît ni facture ni abonnement : il enregistre ce qui a été encaissé, et
 * pour quel objet — sans savoir ce qu'est cet objet.
 *
 * @see docs/03-services/billing/02-data-model.md
 */
final class PaymentLedger
{
    /**
     * Encaissement.
     *
     * Deux lignes et non une : le montant **brut** payé par le client, et la
     * commission de l'agrégateur. Le client a payé 49 663 XAF, la plateforme en
     * a reçu 48 670 — les deux faits sont vrais, et les confondre rendrait le
     * rapprochement bancaire impossible.
     *
     * @return list<PaymentTransaction>
     */
    public function settle(PaymentAttempt $attempt, Money $gross, ?Money $fee): array
    {
        $entries = [$this->record(
            $attempt,
            PaymentTransaction::CHARGE,
            $gross->amount,
            $gross->currency,
            __('payments::messages.payment_received', ['provider' => $attempt->provider]),
        )];

        if ($fee !== null && $fee->isPositive()) {
            $entries[] = $this->record(
                $attempt,
                PaymentTransaction::FEE,
                -$fee->amount,
                $fee->currency,
                __('payments::messages.provider_fee', ['provider' => $attempt->provider]),
            );
        }

        return $entries;
    }

    private function record(
        PaymentAttempt $attempt,
        string $type,
        int $amount,
        string $currency,
        string $description,
    ): PaymentTransaction {
        $intent = $attempt->intent;

        return PaymentTransaction::create([
            'payment_intent_id' => $intent?->id,
            'payment_attempt_id' => $attempt->id,
            'subject_type' => $intent?->subject_type,
            'subject_id' => $intent?->subject_id,
            'payee_organization_id' => $intent?->payee_organization_id,
            'type' => $type,
            'amount' => $amount,
            'currency' => $currency,
            'occurred_at' => now(),
            'description' => $description,
        ]);
    }
}
