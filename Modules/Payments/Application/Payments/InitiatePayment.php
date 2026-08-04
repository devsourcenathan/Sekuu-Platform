<?php

declare(strict_types=1);

namespace Modules\Payments\Application\Payments;

use App\Platform\Contracts\PayableQuote;
use App\Platform\Contracts\PayableRef;
use App\Platform\Contracts\PayerContext;
use App\Platform\Exceptions\DomainException;
use App\Platform\Http\RequestId;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Payments\Domain\AttemptStatus;
use Modules\Payments\Domain\Models\PaymentAttempt;
use Modules\Payments\Domain\Models\PaymentIntent;
use Modules\Payments\Domain\Msisdn;
use Modules\Payments\Infrastructure\Providers\ChargeRequest;
use Modules\Payments\Infrastructure\Providers\ProviderRegistry;

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
        private readonly PayableRegistry $payables,
    ) {}

    /**
     * **Aucun montant en paramètre, et c'est le point.**
     *
     * Il n'existe dans aucune signature accessible à l'appelant : on ne *peut
     * pas* demander à régler 49 663 XAF avec 100 XAF, il n'y a pas de paramètre
     * pour le faire. Le montant est produit par le propriétaire de l'objet, qui
     * seul sait ce qu'il vaut — et qui en profite pour vérifier que ce payeur a
     * le droit de le régler.
     */
    public function handle(
        PayableRef $subject,
        PayerContext $payer,
        string $rawMsisdn,
        ?string $idempotencyKey = null,
    ): PaymentIntent {
        $existing = $this->existingIntent($payer, $idempotencyKey);

        if ($existing !== null) {
            return $existing;
        }

        $quote = $this->payables->for($subject->type)->quote($subject, $payer);

        if ($quote->isRefused()) {
            throw DomainException::conflict((string) $quote->refusalCode, (string) $quote->refusalMessage);
        }

        if (! $quote->isPayable()) {
            throw DomainException::conflict(
                'NOTHING_DUE',
                __('payments::messages.nothing_due'),
            );
        }

        $msisdn = Msisdn::parse($rawMsisdn);

        $intent = $this->createIntent($subject, $payer, $quote, $msisdn, $idempotencyKey);

        return $this->attemptProviders($intent, (string) $quote->description, $msisdn);
    }

    private function attemptProviders(PaymentIntent $intent, string $reference, Msisdn $msisdn): PaymentIntent
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
                description: __('payments::messages.charge_description', ['number' => $reference]),
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
            'failure_reason' => __('payments::messages.all_providers_rejected'),
        ])->save();

        throw new DomainException(
            'PROVIDER_UNAVAILABLE',
            __('payments::messages.all_providers_rejected'),
            503,
        );
    }

    private function createIntent(
        PayableRef $subject,
        PayerContext $payer,
        PayableQuote $quote,
        Msisdn $msisdn,
        ?string $idempotencyKey,
    ): PaymentIntent {
        try {
            // Transaction imbriquée : sur PostgreSQL, une violation d'unicité
            // avorte la transaction courante. Le SAVEPOINT permet de la
            // rattraper sans perdre le contexte.
            return DB::transaction(fn (): PaymentIntent => PaymentIntent::create([
                // Un type et un identifiant que la couche de paiement porte
                // sans jamais les interpréter.
                'subject_type' => $subject->type,
                'subject_id' => $subject->id,
                'payer_type' => $payer->type,
                'payer_id' => $payer->id,
                // `null` = la plateforme encaisse pour elle-même.
                'payee_organization_id' => $quote->payeeOrganizationId,
                'amount' => $quote->amount->amount,
                'currency' => $quote->amount->currency,
                'method' => 'mobile_money',
                'operator' => $msisdn->operator,
                'msisdn' => $msisdn->value,
                'status' => PaymentIntent::PENDING,
                'idempotency_key' => $idempotencyKey,
                'expires_at' => now()->addMinutes((int) config('payments.payment.intent_ttl_minutes', 10)),
                'initiated_by' => $payer->initiatedBy,
                'request_id' => RequestId::current(),
            ]));
        } catch (QueryException $exception) {
            if (! $this->isUniqueViolation($exception)) {
                throw $exception;
            }

            // Le garde-fou contre le client impatient : trois clics ne
            // produisent pas trois invites. La contrainte est en base, pas dans
            // le code — une vérification applicative perdrait la course.
            $byKey = $this->existingIntent($payer, $idempotencyKey);

            if ($byKey !== null) {
                return $byKey;
            }

            throw DomainException::conflict(
                'PAYMENT_ALREADY_PENDING',
                __('payments::messages.payment_already_pending'),
            );
        }
    }

    /**
     * Intention déjà créée pour cette clé, **et pour ce payeur**.
     *
     * Le filtre sur le payeur n'est pas décoratif : la recherche était globale,
     * et deux produits dont les clients dérivent leurs clés du métier —
     * `invoice-123`, `order-1` — se seraient renvoyé mutuellement leurs
     * intentions, montant et tentatives compris, chacun croyant avoir lancé son
     * propre paiement.
     */
    private function existingIntent(PayerContext $payer, ?string $idempotencyKey): ?PaymentIntent
    {
        if ($idempotencyKey === null) {
            return null;
        }

        return PaymentIntent::query()
            ->where('payer_type', $payer->type)
            ->where('payer_id', $payer->id)
            ->where('idempotency_key', $idempotencyKey)
            ->first();
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
