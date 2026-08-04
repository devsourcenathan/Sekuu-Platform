<?php

declare(strict_types=1);

namespace Modules\Billing\Application\Invoicing;

use App\Platform\Contracts\PayableQuote;
use App\Platform\Contracts\PayableRef;
use App\Platform\Contracts\PayableSource;
use App\Platform\Contracts\PayerContext;
use App\Platform\Contracts\PaymentSettlement;
use App\Platform\Events\PublishesDomainEvents;
use App\Platform\Support\Money;
use Modules\Billing\Application\Notifications\AddressesTheOrganization;
use Modules\Billing\Application\Subscriptions\ActivateSubscription;
use Modules\Billing\Domain\Models\Invoice;

/**
 * Billing, vu depuis la couche de paiement.
 *
 * Tout ce que `SettlePayment` faisait de spécifiquement facturier — régler la
 * facture, publier `billing.invoice.paid`, activer l'abonnement — est ici. Le
 * couplage n'a pas été supprimé : il a été **rapatrié chez celui à qui il
 * appartient**.
 *
 * @see docs/05-analyses/extraction-payments.md
 */
final class InvoicePayable implements PayableSource
{
    use AddressesTheOrganization;
    use PublishesDomainEvents;

    public const TYPE = 'billing.invoice';

    public function __construct(private readonly ActivateSubscription $activation) {}

    /**
     * Le montant vient de la facture, chargée ici et nulle part ailleurs.
     */
    public function quote(PayableRef $ref, PayerContext $payer): PayableQuote
    {
        $invoice = Invoice::query()->find($ref->id);

        if ($invoice === null) {
            return PayableQuote::refused('INVOICE_NOT_FOUND', __('billing::messages.invoice_not_found'));
        }

        // Une facture appartient à une organisation, et se règle par elle.
        // Sans ce contrôle, connaître un identifiant suffirait à déclencher une
        // invite sur le téléphone de quelqu'un d'autre.
        if (! $payer->isOrganization() || $payer->id !== $invoice->organization_id) {
            return PayableQuote::refused('INVOICE_NOT_FOUND', __('billing::messages.invoice_not_found'));
        }

        if ($invoice->status === Invoice::VOID) {
            return PayableQuote::refused('INVOICE_VOIDED', __('billing::messages.invoice_voided'));
        }

        if (! $invoice->isPayable()) {
            return PayableQuote::refused('INVOICE_ALREADY_PAID', __('billing::messages.invoice_already_paid'));
        }

        return PayableQuote::due(
            $invoice->outstanding(),
            // Ce libellé part réellement chez l'agrégateur et atterrit sur le
            // relevé du client : il doit lui parler.
            __('payments::messages.charge_description', ['number' => $invoice->number]),
            // Sekuu encaisse pour elle-même.
            payeeOrganizationId: null,
        );
    }

    /**
     * La facture est réglée sur le montant **brut**. Traiter le net comme le
     * montant payé la laisserait éternellement impayée à hauteur de la
     * commission — et l'abonnement jamais activé.
     */
    public function settled(PaymentSettlement $settlement): void
    {
        $invoice = Invoice::query()->lockForUpdate()->find($settlement->subject->id);

        // Idempotente : le même règlement peut arriver deux fois, par callback
        // puis par sondage.
        if ($invoice === null || $invoice->status === Invoice::PAID) {
            return;
        }

        $paid = $invoice->amount_paid + $settlement->amount->amount;
        $settled = $paid >= $invoice->total;

        $invoice->forceFill([
            'amount_paid' => $paid,
            'status' => $settled ? Invoice::PAID : $invoice->status,
            'paid_at' => $settled ? now() : null,
        ])->save();

        if (! $settled) {
            return;
        }

        $this->publish('billing.invoice.paid', [
            'invoice_id' => $invoice->id,
            'number' => $invoice->number,
            'total' => $invoice->total,
            'currency' => $invoice->currency,
            ...$this->addressed($invoice->organization_id, [
                'invoice_number' => $invoice->number,
                'amount' => $invoice->totalMoney()->format(),
                'paid_at' => now()->translatedFormat('d F Y'),
            ]),
        ], $invoice->organization_id);

        if ($invoice->subscription_id !== null) {
            $this->activation->fromInvoice($invoice);
        }
    }

    /**
     * L'événement reste `billing.payment.failed`, publié par Billing.
     *
     * Notify associe les événements à des templates par un tableau littéral :
     * une clé renommée ne tombe plus, **sans exception ni journal**. Le SMS
     * d'échec disparaîtrait en silence, au moment précis où le client est le
     * plus susceptible de recommencer.
     */
    public function failed(PaymentSettlement $settlement): void
    {
        $invoice = Invoice::query()->find($settlement->subject->id);

        if ($invoice === null) {
            return;
        }

        $this->publish('billing.payment.failed', [
            'payment_intent_id' => $settlement->paymentIntentId,
            'invoice_id' => $invoice->id,
            'failure_code' => $settlement->failureCode,
            // SMS : le client vient de tenter un paiement qui n'a pas abouti.
            ...$this->addressed($invoice->organization_id, [
                'amount' => Money::of($settlement->amount->amount, $invoice->currency)->format(),
                'reason' => (string) ($settlement->failureReason ?? ''),
            ], withPhone: true),
        ], $invoice->organization_id);
    }
}
