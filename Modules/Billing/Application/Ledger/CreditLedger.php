<?php

declare(strict_types=1);

namespace Modules\Billing\Application\Ledger;

use Modules\Billing\Domain\Models\Invoice;
use Modules\Billing\Domain\Models\PaymentAttempt;
use Modules\Billing\Domain\Models\Transaction;
use Modules\Billing\Domain\Money;

/**
 * Registre des mouvements d'argent.
 *
 * Le solde de crédit n'est **jamais stocké** : il est la somme des lignes. Un
 * solde stocké et un registre finissent par diverger, et c'est alors le
 * registre qui a raison — autant ne pas créer la divergence.
 *
 * @see docs/03-services/billing/02-data-model.md
 */
final class CreditLedger
{
    public function balance(string $organizationId, ?string $currency = null): Money
    {
        $currency ??= (string) config('billing.default_currency');

        $sum = (int) Transaction::query()
            ->where('organization_id', $organizationId)
            ->where('currency', $currency)
            ->whereIn('type', Transaction::creditTypes())
            ->sum('amount');

        return Money::of($sum, $currency);
    }

    /**
     * Crédit né d'une proration ou d'un avoir.
     *
     * Jamais d'un remboursement en espèces : un remboursement Mobile Money est
     * lent, coûteux et souvent manuel.
     */
    public function credit(string $organizationId, Money $amount, string $description): Transaction
    {
        return $this->record($organizationId, Transaction::CREDIT, $amount->amount, $amount->currency, $description);
    }

    public function consume(string $organizationId, Money $amount, Invoice $invoice): Transaction
    {
        return $this->record(
            $organizationId,
            Transaction::DEBIT,
            -$amount->amount,
            $amount->currency,
            __('billing::messages.credit_applied_to_invoice', ['number' => $invoice->number]),
            invoiceId: $invoice->id,
        );
    }

    /**
     * Encaissement.
     *
     * Deux lignes et non une : le montant **brut** payé par le client, et la
     * commission de l'agrégateur. Le client a payé 49 663 XAF, la plateforme en
     * a reçu 48 670 — les deux faits sont vrais, et les confondre rendrait le
     * rapprochement bancaire impossible.
     *
     * @return list<Transaction>
     */
    public function settle(PaymentAttempt $attempt, Money $gross, ?Money $fee): array
    {
        $intent = $attempt->intent;

        $entries = [$this->record(
            $intent->organization_id,
            Transaction::CHARGE,
            $gross->amount,
            $gross->currency,
            __('billing::messages.payment_received', ['provider' => $attempt->provider]),
            invoiceId: $intent->invoice_id,
            intentId: $intent->id,
            attemptId: $attempt->id,
        )];

        if ($fee !== null && $fee->isPositive()) {
            $entries[] = $this->record(
                $intent->organization_id,
                Transaction::FEE,
                -$fee->amount,
                $fee->currency,
                __('billing::messages.provider_fee', ['provider' => $attempt->provider]),
                invoiceId: $intent->invoice_id,
                intentId: $intent->id,
                attemptId: $attempt->id,
            );
        }

        return $entries;
    }

    private function record(
        string $organizationId,
        string $type,
        int $amount,
        string $currency,
        string $description,
        ?string $invoiceId = null,
        ?string $intentId = null,
        ?string $attemptId = null,
    ): Transaction {
        return Transaction::create([
            'organization_id' => $organizationId,
            'invoice_id' => $invoiceId,
            'payment_intent_id' => $intentId,
            'payment_attempt_id' => $attemptId,
            'type' => $type,
            'amount' => $amount,
            'currency' => $currency,
            'occurred_at' => now(),
            'description' => $description,
        ]);
    }
}
