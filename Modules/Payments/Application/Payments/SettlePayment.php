<?php

declare(strict_types=1);

namespace Modules\Payments\Application\Payments;

use App\Platform\Contracts\PayableRef;
use App\Platform\Contracts\PayerContext;
use App\Platform\Contracts\PaymentSettlement;
use App\Platform\Events\PublishesDomainEvents;
use App\Platform\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Payments\Domain\AttemptStatus;
use Modules\Payments\Domain\Models\PaymentAttempt;
use Modules\Payments\Domain\Models\PaymentIntent;
use Modules\Payments\Infrastructure\Providers\ChargeOutcome;

/**
 * Constatation d'un paiement.
 *
 * Le point d'entrée unique par lequel passent l'appel de débit, le callback et
 * le sondage. Trois chemins, une seule écriture — sans quoi le même paiement
 * serait constaté deux fois selon qui arrive le premier.
 *
 * Ne sait plus ce qu'un paiement règle : il remet l'issue au propriétaire de
 * l'objet, résolu par `subject_type`.
 */
final class SettlePayment
{
    use PublishesDomainEvents;

    public function __construct(
        private readonly PaymentLedger $ledger,
        private readonly PayableRegistry $payables,
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
            // Une tentative aboutie ne perd pas sa date de règlement parce
            // qu'un sondage tardif a échoué : `settled_at` ne se remet jamais
            // à null.
            'settled_at' => $outcome->status->isTerminal() ? now() : $attempt->settled_at,
        ])->save();

        return $attempt;
    }

    /**
     * Report de l'état d'une tentative sur son intention, puis sur l'objet payé.
     */
    public function applyToIntent(PaymentIntent $intent, PaymentAttempt $attempt): PaymentIntent
    {
        return DB::transaction(function () use ($intent, $attempt): PaymentIntent {
            // Verrou sur l'intention, et non seulement sur l'objet payé : deux
            // exécutions concurrentes — le sondage et un callback, ou deux des
            // trois livraisons qu'un agrégateur envoie pour un seul paiement —
            // pouvaient lire toutes deux `processing` et encaisser deux fois.
            $intent = PaymentIntent::query()->lockForUpdate()->find($intent->id) ?? $intent;

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
                // Le propriétaire prévient son client dans ses propres termes,
                // et publie l'événement que Notify connaît.
                $this->notifyOwner($intent, $attempt, succeeded: false);
            }

            return $intent;
        });
    }

    private function recordSuccess(PaymentIntent $intent, PaymentAttempt $attempt): void
    {
        // Le montant rapporté par l'agrégateur n'est pas cru sur parole : il
        // sert de constat, mais c'est l'intention enregistrée qui fait foi.
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

        $this->publish('payments.payment.succeeded', [
            'payment_intent_id' => $intent->id,
            'subject_type' => $intent->subject_type,
            'subject_id' => $intent->subject_id,
            'amount' => $gross->amount,
            'currency' => $gross->currency,
        ], $intent->contextOrganizationId());

        $this->notifyOwner($intent, $attempt, succeeded: true);
    }

    /**
     * Remise de l'issue au propriétaire de l'objet, **dans la transaction**.
     *
     * Passer par un événement créerait une fenêtre où l'argent est encaissé et
     * le service fermé, qu'un consommateur en échec définitif rendrait
     * permanente.
     */
    private function notifyOwner(PaymentIntent $intent, PaymentAttempt $attempt, bool $succeeded): void
    {
        if (! $this->payables->knows($intent->subject_type)) {
            // Un paiement qu'on ne sait rattacher à rien doit se voir : c'est
            // de l'argent encaissé sans service rendu.
            Log::error('Paiement sans propriétaire connu.', [
                'payment_intent_id' => $intent->id,
                'subject_type' => $intent->subject_type,
            ]);

            return;
        }

        $settlement = new PaymentSettlement(
            paymentIntentId: $intent->id,
            subject: new PayableRef($intent->subject_type, $intent->subject_id),
            payer: new PayerContext($intent->payer_type, $intent->payer_id, $intent->initiated_by),
            amount: $intent->money(),
            provider: $attempt->provider,
            failureCode: $intent->failure_code,
            failureReason: $intent->failure_reason,
        );

        $source = $this->payables->for($intent->subject_type);

        $succeeded ? $source->settled($settlement) : $source->failed($settlement);
    }
}
