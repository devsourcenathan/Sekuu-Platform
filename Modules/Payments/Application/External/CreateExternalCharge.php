<?php

declare(strict_types=1);

namespace Modules\Payments\Application\External;

use App\Platform\Contracts\PayableRef;
use App\Platform\Contracts\PayerContext;
use App\Platform\Exceptions\DomainException;
use App\Platform\Support\Money;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Modules\Payments\Application\Payments\InitiatePayment;
use Modules\Payments\Application\Payments\PayableRegistry;
use Modules\Payments\Domain\Models\ExternalCharge;
use Throwable;

/**
 * Déclarer un prix, puis demander l'encaissement.
 *
 * Deux écritures et un appel, dans cet ordre : la charge d'abord, le paiement
 * ensuite. L'inverse serait impossible — `quote()` relit la charge en base, et
 * ne peut donc pas la précéder.
 *
 * @see docs/03-services/payments/07-external-api.md
 */
final class CreateExternalCharge
{
    /**
     * Un produit externe ne peut pas se réclamer d'un compte de la plateforme.
     *
     * Déclarer `identity.user` reviendrait à faire porter le paiement par un
     * utilisateur Sekuu que le produit n'authentifie pas, et à le faire
     * apparaître dans les paiements de son organisation.
     */
    private const RESERVED_PAYER_PREFIX = 'identity.';

    public function __construct(
        private readonly InitiatePayment $payments,
        private readonly PayableRegistry $payables,
    ) {}

    public function handle(
        string $organizationId,
        ?string $apiKeyId,
        string $subjectType,
        string $subjectId,
        string $payerType,
        string $payerId,
        int $amount,
        string $currency,
        string $description,
        string $rawMsisdn,
        ?string $idempotencyKey = null,
    ): DeclaredCharge {
        $this->guardPayerType($payerType);
        $this->guardSubjectType($subjectType);

        $charge = $this->declare(
            $organizationId, $apiKeyId, $subjectType, $subjectId,
            $payerType, $payerId, $amount, $currency, $description,
        );

        try {
            $intent = $this->payments->handle(
                subject: new PayableRef($subjectType, $subjectId),
                payer: new PayerContext($payerType, $payerId),
                rawMsisdn: $rawMsisdn,
                idempotencyKey: $idempotencyKey,
            );

            return new DeclaredCharge($charge->fresh(), $intent);
        } catch (Throwable $exception) {
            // Aucun agrégateur n'a accepté, ou le numéro est invalide : la
            // charge déclarée ne doit pas rester en attente, sans quoi elle
            // bloquerait indéfiniment toute nouvelle tentative sur cet objet.
            //
            // On ne notifie pas le produit : il reçoit l'erreur en réponse
            // synchrone, et lui envoyer en plus un webhook d'échec lui ferait
            // traiter deux fois le même refus.
            $charge->forceFill([
                'status' => ExternalCharge::FAILED,
                'settled_at' => now(),
            ])->save();

            throw $exception;
        }
    }

    private function declare(
        string $organizationId,
        ?string $apiKeyId,
        string $subjectType,
        string $subjectId,
        string $payerType,
        string $payerId,
        int $amount,
        string $currency,
        string $description,
    ): ExternalCharge {
        // Valide la devise et son exposant avant toute écriture : `45000` en
        // XAF vaut 45 000 francs, en EUR 450 euros. Une devise inconnue doit
        // échouer ici, pas au moment d'afficher un montant.
        Money::of($amount, $currency);

        try {
            return DB::transaction(fn (): ExternalCharge => ExternalCharge::create([
                'organization_id' => $organizationId,
                'api_key_id' => $apiKeyId,
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'payer_type' => $payerType,
                'payer_id' => $payerId,
                'amount' => $amount,
                'currency' => mb_strtoupper($currency),
                'description' => $description,
                'status' => ExternalCharge::PENDING,
            ]));
        } catch (QueryException $exception) {
            if (($exception->errorInfo[0] ?? null) !== '23505') {
                throw $exception;
            }

            // Une charge en attente existe déjà sur cet objet. C'est le même
            // garde-fou que côté intentions, une couche plus haut : le produit
            // doit le lire comme « un paiement est déjà en cours », et non
            // comme une erreur à réessayer.
            throw DomainException::conflict(
                'PAYMENT_ALREADY_PENDING',
                __('payments::messages.payment_already_pending'),
            );
        }
    }

    private function guardPayerType(string $payerType): void
    {
        if (str_starts_with($payerType, self::RESERVED_PAYER_PREFIX)) {
            throw DomainException::unprocessable(
                'PAYER_TYPE_NOT_ALLOWED',
                __('payments::messages.payer_type_not_allowed'),
            );
        }
    }

    /**
     * Le type doit être servi par `ExternalPayable`, et pas seulement connu.
     *
     * C'est la seconde borne, indépendante de la clé : une clé mal émise ne
     * suffit pas à faire payer un objet dont le prix vit dans le monolithe.
     * Sans cette vérification, `quote()` irait interroger `InvoicePayable` avec
     * l'identifiant d'une facture.
     */
    private function guardSubjectType(string $subjectType): void
    {
        if (! $this->payables->knows($subjectType)
            || ! $this->payables->for($subjectType) instanceof ExternalPayable) {
            throw DomainException::forbidden(
                'SUBJECT_TYPE_NOT_ALLOWED',
                __('payments::messages.subject_type_not_external'),
            );
        }
    }
}
