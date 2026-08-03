<?php

declare(strict_types=1);

namespace Modules\Billing\Application\Payments;

use App\Platform\Exceptions\DomainException;
use App\Platform\Http\RequestId;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Billing\Domain\AttemptStatus;
use Modules\Billing\Domain\Models\Invoice;
use Modules\Billing\Domain\Models\PaymentAttempt;
use Modules\Billing\Domain\Models\PaymentIntent;
use Modules\Billing\Domain\Msisdn;
use Modules\Billing\Infrastructure\Providers\ChargeRequest;
use Modules\Billing\Infrastructure\Providers\ProviderRegistry;

/**
 * Demande de paiement d'une facture, avec bascule entre agrégateurs.
 *
 * **La règle de bascule est la partie critique de ce module.** On ne réessaie
 * chez l'agrégateur suivant que si l'invite n'est jamais partie sur le
 * téléphone du client. Tout le reste produirait un double débit — une faute que
 * le client découvre sur son relevé, et qu'un remboursement Mobile Money rend
 * pénible à corriger.
 *
 * @see docs/04-decisions/adr-0008-payment-aggregators-failover.md
 */
final class InitiatePayment
{
    public function __construct(
        private readonly ProviderRegistry $providers,
        private readonly SettlePayment $settlement,
    ) {}

    public function handle(
        Invoice $invoice,
        string $rawMsisdn,
        ?string $idempotencyKey = null,
        ?string $initiatedBy = null,
    ): PaymentIntent {
        if ($invoice->status === Invoice::VOID) {
            throw DomainException::conflict('INVOICE_VOIDED', __('billing::messages.invoice_voided'));
        }

        if (! $invoice->isPayable()) {
            throw DomainException::conflict('INVOICE_ALREADY_PAID', __('billing::messages.invoice_already_paid'));
        }

        $existing = $this->existingIntent($idempotencyKey);

        if ($existing !== null) {
            return $existing;
        }

        $msisdn = Msisdn::parse($rawMsisdn);

        // Le montant vient de la facture, jamais de l'appelant : l'accepter du
        // corps permettrait de régler 49 663 XAF avec 100 XAF.
        $amount = $invoice->outstanding();

        $intent = $this->createIntent($invoice, $msisdn, $amount->amount, $idempotencyKey, $initiatedBy);

        return $this->attemptProviders($intent, $invoice, $msisdn);
    }

    private function attemptProviders(PaymentIntent $intent, Invoice $invoice, Msisdn $msisdn): PaymentIntent
    {
        $providers = $this->providers->forOperator($msisdn->operator);
        $priority = 0;

        foreach ($providers as $provider) {
            $priority++;

            // Écrite **avant** l'appel : sans elle, un appel qui expire laisse
            // une tentative dont on ignore si elle a abouti — et c'est
            // précisément la question dont dépend la bascule.
            $attempt = PaymentAttempt::create([
                'payment_intent_id' => $intent->id,
                'provider' => $provider->name(),
                'priority' => $priority,
                'merchant_reference' => $this->reference(),
                'status' => AttemptStatus::Created,
                'started_at' => now(),
            ]);

            $outcome = $provider->charge(new ChargeRequest(
                money: $intent->money(),
                msisdn: $msisdn,
                merchantReference: $attempt->merchant_reference,
                description: __('billing::messages.charge_description', ['number' => $invoice->number]),
            ));

            $this->settlement->applyToAttempt($attempt, $outcome);

            if (! $attempt->fresh()->allowsFailover()) {
                // Soit c'est parti, soit c'est terminé, soit on ne sait pas.
                // Dans les trois cas, on n'essaie personne d'autre.
                return $this->settlement->applyToIntent($intent->fresh(), $attempt->fresh());
            }

            Log::info('Bascule vers l\'agrégateur suivant.', [
                'intent_id' => $intent->id,
                'provider' => $provider->name(),
                'failure_code' => $attempt->failure_code,
                'msisdn' => $msisdn->masked(),
            ]);
        }

        // Tous les agrégateurs ont refusé la demande — sans qu'aucune invite ne
        // parte. L'intention échoue, et aucun client n'a été débité.
        $intent->forceFill([
            'status' => PaymentIntent::FAILED,
            'failure_code' => 'PROVIDER_UNAVAILABLE',
            'failure_reason' => __('billing::messages.all_providers_rejected'),
        ])->save();

        throw new DomainException(
            'PROVIDER_UNAVAILABLE',
            __('billing::messages.all_providers_rejected'),
            503,
        );
    }

    private function createIntent(
        Invoice $invoice,
        Msisdn $msisdn,
        int $amount,
        ?string $idempotencyKey,
        ?string $initiatedBy,
    ): PaymentIntent {
        try {
            // Transaction imbriquée : sur PostgreSQL, une violation d'unicité
            // avorte la transaction courante. Le SAVEPOINT permet de la
            // rattraper sans perdre le contexte.
            return DB::transaction(fn (): PaymentIntent => PaymentIntent::create([
                'organization_id' => $invoice->organization_id,
                'invoice_id' => $invoice->id,
                'amount' => $amount,
                'currency' => $invoice->currency,
                'method' => 'mobile_money',
                'operator' => $msisdn->operator,
                'msisdn' => $msisdn->value,
                'status' => PaymentIntent::PENDING,
                'idempotency_key' => $idempotencyKey,
                'expires_at' => now()->addMinutes((int) config('billing.payment.intent_ttl_minutes', 10)),
                'initiated_by' => $initiatedBy,
                'request_id' => RequestId::current(),
            ]));
        } catch (QueryException $exception) {
            if (! $this->isUniqueViolation($exception)) {
                throw $exception;
            }

            // Le garde-fou contre le client impatient : trois clics ne
            // produisent pas trois invites. La contrainte est en base, pas dans
            // le code — une vérification applicative perdrait la course.
            $byKey = $this->existingIntent($idempotencyKey);

            if ($byKey !== null) {
                return $byKey;
            }

            throw DomainException::conflict(
                'PAYMENT_ALREADY_PENDING',
                __('billing::messages.payment_already_pending'),
            );
        }
    }

    private function existingIntent(?string $idempotencyKey): ?PaymentIntent
    {
        if ($idempotencyKey === null) {
            return null;
        }

        return PaymentIntent::query()->where('idempotency_key', $idempotencyKey)->first();
    }

    /**
     * Référence marchande : notre clé de corrélation, la seule qui permette de
     * retrouver une transaction dont on n'a jamais reçu la réponse.
     */
    private function reference(): string
    {
        return 'SKU'.mb_strtoupper(Str::random(16));
    }

    private function isUniqueViolation(QueryException $exception): bool
    {
        return ($exception->errorInfo[0] ?? null) === '23505';
    }
}
