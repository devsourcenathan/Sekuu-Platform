<?php

declare(strict_types=1);

namespace Modules\Billing\Application\Ledger;

use App\Platform\Support\Money;
use Modules\Billing\Domain\Models\CreditEntry;
use Modules\Billing\Domain\Models\Invoice;

/**
 * Registre de crédit commercial.
 *
 * Le solde n'est **jamais stocké** : il est la somme des lignes. Un solde
 * stocké et un registre finissent par diverger, et c'est alors le registre qui
 * a raison — autant ne pas créer la divergence.
 *
 * L'encaissement, lui, ne passe plus par ici : il appartient au registre de
 * caisse. Les deux étaient colocalisés dans une table `transactions`, mais
 * `charge` règle une facture et `fee` est une charge de la plateforme —
 * ni l'un ni l'autre n'est une somme due au client.
 *
 * @see docs/03-services/billing/02-data-model.md
 */
final class CreditLedger
{
    public function balance(string $organizationId, ?string $currency = null): Money
    {
        $currency ??= (string) config('sekuu.default_currency');

        $sum = (int) CreditEntry::query()
            ->where('organization_id', $organizationId)
            ->where('currency', $currency)
            ->sum('amount');

        return Money::of($sum, $currency);
    }

    /**
     * Crédit né d'une proration ou d'un avoir.
     *
     * Jamais d'un remboursement en espèces : un remboursement Mobile Money est
     * lent, coûteux et souvent manuel.
     */
    public function credit(string $organizationId, Money $amount, string $description): CreditEntry
    {
        return $this->record($organizationId, CreditEntry::CREDIT, $amount->amount, $amount->currency, $description);
    }

    public function consume(string $organizationId, Money $amount, Invoice $invoice): CreditEntry
    {
        return $this->record(
            $organizationId,
            CreditEntry::DEBIT,
            -$amount->amount,
            $amount->currency,
            __('billing::messages.credit_applied_to_invoice', ['number' => $invoice->number]),
            invoiceId: $invoice->id,
        );
    }

    private function record(
        string $organizationId,
        string $type,
        int $amount,
        string $currency,
        string $description,
        ?string $invoiceId = null,
        ?string $intentId = null,
    ): CreditEntry {
        return CreditEntry::create([
            'organization_id' => $organizationId,
            'invoice_id' => $invoiceId,
            'payment_intent_id' => $intentId,
            'type' => $type,
            'amount' => $amount,
            'currency' => $currency,
            'occurred_at' => now(),
            'description' => $description,
        ]);
    }
}
