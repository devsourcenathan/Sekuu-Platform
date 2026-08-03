<?php

declare(strict_types=1);

namespace Modules\Billing\Application\Payments;

use App\Platform\Events\PublishesDomainEvents;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Billing\Application\Ledger\CreditLedger;
use Modules\Billing\Application\Subscriptions\ActivateSubscription;
use Modules\Billing\Domain\AttemptStatus;
use Modules\Billing\Domain\Models\Invoice;
use Modules\Billing\Domain\Models\PaymentAttempt;
use Modules\Billing\Domain\Models\PaymentIntent;
use Modules\Billing\Domain\Money;
use Modules\Billing\Infrastructure\Providers\ChargeOutcome;

/**
 * Constatation d'un paiement.
 *
 * Le point d'entrée unique par lequel passent l'appel de débit, le callback et
 * le sondage. Trois chemins, une seule écriture — sans quoi le même paiement
 * serait constaté deux fois selon qui arrive le premier.
 */
final class SettlePayment
{
    use PublishesDomainEvents;

    public function __construct(
        private readonly CreditLedger $ledger,
        private readonly ActivateSubscription $activation,
    ) {}

    public function applyToAttempt(PaymentAttempt $attempt, ChargeOutcome $outcome): PaymentAttempt
    {
        $attempt->forceFill([
            'status' => $outcome->status,
            // Jamais rétrogradé : une invite partie le reste. Écraser ce
            // drapeau par un `false` venu d'un statut mal traduit rouvrirait la
            // porte au double débit.
            'customer_prompted' => $attempt->customer_prompted || $outcome->customerPrompted,
            'provider_ref' => $outcome->providerRef ?? $attempt->provider_ref,
            'raw_status' => $outcome->rawStatus ?? $attempt->raw_status,
            'failure_code' => $outcome->failureCode,
            'failure_reason' => $outcome->failureReason,
            'gross_amount' => $outcome->grossAmount ?? $attempt->gross_amount,
            'fee_amount' => $outcome->feeAmount ?? $attempt->fee_amount,
            'net_amount' => $outcome->netAmount ?? $attempt->net_amount,
            'settled_at' => $outcome->status->isTerminal() ? now() : null,
        ])->save();

        return $attempt;
    }

    /**
     * Report de l'état d'une tentative sur son intention, puis sur la facture.
     */
    public function applyToIntent(PaymentIntent $intent, PaymentAttempt $attempt): PaymentIntent
    {
        return DB::transaction(function () use ($intent, $attempt): PaymentIntent {
            $status = match ($attempt->status) {
                AttemptStatus::Succeeded => PaymentIntent::SUCCEEDED,
                AttemptStatus::Failed, AttemptStatus::Rejected => PaymentIntent::FAILED,
                AttemptStatus::Expired => PaymentIntent::EXPIRED,
                AttemptStatus::Processing => PaymentIntent::PROCESSING,
                default => PaymentIntent::PENDING,
            };

            // Une intention réussie ne redevient jamais autre chose : un
            // callback tardif ne doit pas défaire un encaissement constaté.
            if ($intent->status === PaymentIntent::SUCCEEDED) {
                return $intent;
            }

            $intent->forceFill([
                'status' => $status,
                'failure_code' => $attempt->failure_code,
                'failure_reason' => $attempt->failure_reason,
            ])->save();

            if ($status === PaymentIntent::SUCCEEDED) {
                $this->recordSuccess($intent, $attempt);
            }

            if ($status === PaymentIntent::FAILED) {
                $this->publish('billing.payment.failed', [
                    'payment_intent_id' => $intent->id,
                    'invoice_id' => $intent->invoice_id,
                    'failure_code' => $intent->failure_code,
                ], $intent->organization_id);
            }

            return $intent;
        });
    }

    private function recordSuccess(PaymentIntent $intent, PaymentAttempt $attempt): void
    {
        // Le montant rapporté par l'agrégateur n'est pas cru sur parole : il
        // sert de constat, mais c'est l'intention enregistrée qui fait foi.
        // Chez un agrégateur qui authentifie ses callbacks par secret partagé
        // plutôt que par signature, croire le montant reçu serait une faille.
        $gross = $intent->money();
        $reported = $attempt->gross_amount;

        if ($reported !== null && $reported !== $gross->amount) {
            Log::warning('Montant rapporté différent du montant attendu.', [
                'payment_intent_id' => $intent->id,
                'expected' => $gross->amount,
                'reported' => $reported,
                'provider' => $attempt->provider,
            ]);
        }

        $fee = $attempt->fee_amount === null
            ? null
            : Money::of($attempt->fee_amount, $intent->currency);

        $this->ledger->settle($attempt, $gross, $fee);

        $this->publish('billing.payment.succeeded', [
            'payment_intent_id' => $intent->id,
            'invoice_id' => $intent->invoice_id,
            'amount' => $gross->amount,
            'currency' => $gross->currency,
        ], $intent->organization_id);

        if ($intent->invoice_id !== null) {
            $this->markInvoicePaid($intent, $gross);
        }
    }

    /**
     * La facture est réglée sur le montant **brut**. Traiter le net comme le
     * montant payé la laisserait éternellement impayée à hauteur de la
     * commission — et l'abonnement jamais activé.
     */
    private function markInvoicePaid(PaymentIntent $intent, Money $gross): void
    {
        $invoice = Invoice::query()->lockForUpdate()->find($intent->invoice_id);

        if ($invoice === null || $invoice->status === Invoice::PAID) {
            return;
        }

        $paid = $invoice->amount_paid + $gross->amount;
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
        ], $invoice->organization_id);

        if ($invoice->subscription_id !== null) {
            $this->activation->fromInvoice($invoice);
        }
    }
}
