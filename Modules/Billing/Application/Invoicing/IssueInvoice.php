<?php

declare(strict_types=1);

namespace Modules\Billing\Application\Invoicing;

use App\Platform\Events\PublishesDomainEvents;
use Illuminate\Support\Facades\DB;
use Modules\Billing\Application\Ledger\CreditLedger;
use Modules\Billing\Domain\Models\Invoice;
use Modules\Billing\Domain\Models\Subscription;
use Modules\Billing\Domain\Money;

/**
 * Émission d'une facture.
 *
 * Le taux de TVA est **figé** ici : une facture est un document légal, elle
 * doit rester lisible telle qu'émise même si le taux change ensuite.
 *
 * @see docs/03-services/billing/02-data-model.md
 */
final class IssueInvoice
{
    use PublishesDomainEvents;

    public function __construct(private readonly CreditLedger $credit) {}

    /**
     * @param  list<array{description: string, unit_amount: int, quantity?: int, product_id?: string|null}>  $lines
     * @param  array<string, mixed>  $billingDetails
     */
    public function handle(
        string $organizationId,
        array $lines,
        ?string $currency = null,
        ?Subscription $subscription = null,
        ?string $periodStart = null,
        ?string $periodEnd = null,
        array $billingDetails = [],
    ): Invoice {
        $currency ??= (string) config('billing.default_currency');

        return DB::transaction(function () use (
            $organizationId, $lines, $currency, $subscription, $periodStart, $periodEnd, $billingDetails
        ): Invoice {
            $subtotal = Money::zero($currency);

            foreach ($lines as $line) {
                $quantity = $line['quantity'] ?? 1;
                $subtotal = $subtotal->plus(Money::of($line['unit_amount'] * $quantity, $currency));
            }

            $taxRate = $this->taxRate();
            $tax = $subtotal->multipliedBy($taxRate);

            // Le crédit disponible s'impute avant émission, et jamais au-delà
            // du dû : un crédit supérieur ne produit pas une facture négative,
            // le reliquat reste au registre.
            $due = $subtotal->plus($tax);
            $available = $this->credit->balance($organizationId, $currency);
            $applied = $available->isPositive() ? $available->min($due) : Money::zero($currency);

            $invoice = Invoice::create([
                'organization_id' => $organizationId,
                'subscription_id' => $subscription?->id,
                'number' => InvoiceNumber::next(),
                'status' => Invoice::OPEN,
                'currency' => $currency,
                'subtotal' => $subtotal->amount,
                'tax_rate' => $taxRate,
                'tax_amount' => $tax->amount,
                'credit_applied' => $applied->amount,
                'total' => $due->minus($applied)->amount,
                'amount_paid' => 0,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'issued_at' => now(),
                'due_at' => now()->addDays(7),
                'billing_details' => $billingDetails,
            ]);

            foreach ($lines as $line) {
                $quantity = $line['quantity'] ?? 1;

                $invoice->lines()->create([
                    'description' => $line['description'],
                    'quantity' => $quantity,
                    'unit_amount' => $line['unit_amount'],
                    'amount' => $line['unit_amount'] * $quantity,
                    'product_id' => $line['product_id'] ?? null,
                ]);
            }

            if ($applied->isPositive()) {
                $this->credit->consume($organizationId, $applied, $invoice);
            }

            // Une facture dont le total est nul — crédit couvrant tout, ou plan
            // gratuit — est réglée d'emblée. Attendre un paiement de zéro
            // laisserait l'abonnement inactif indéfiniment.
            if ($invoice->total === 0) {
                $invoice->forceFill(['status' => Invoice::PAID, 'paid_at' => now()])->save();
            }

            $this->publish('billing.invoice.issued', [
                'invoice_id' => $invoice->id,
                'number' => $invoice->number,
                'total' => $invoice->total,
                'currency' => $invoice->currency,
                'status' => $invoice->status,
                'due_at' => $invoice->due_at?->toIso8601ZuluString(),
            ], $organizationId);

            return $invoice->fresh(['lines']);
        });
    }

    /**
     * Taux du pays de facturation. Cameroun : TVA 18 % + centimes additionnels
     * communaux = 19,25 %.
     */
    private function taxRate(): float
    {
        $country = (string) config('billing.default_country');

        return (float) config('billing.tax.'.$country, 0.0);
    }
}
