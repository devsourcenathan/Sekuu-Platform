<?php

declare(strict_types=1);

namespace Modules\Payments\Application\External;

use App\Platform\Contracts\RefundSettlement;
use App\Platform\Http\RequestId;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Payments\Domain\Models\ExternalCharge;
use Modules\Payments\Domain\Models\PaymentDelivery;
use Modules\Payments\Domain\Models\PaymentEndpoint;
use Modules\Payments\Infrastructure\External\DeliverPaymentEvent;

/**
 * Enfile l'issue d'un paiement vers le produit qui l'a déclaré.
 *
 * **Appelée dans la transaction d'encaissement.** Elle n'écrit donc qu'une
 * ligne : aucun appel réseau ici, sinon un produit lent tiendrait des verrous
 * de caisse le temps de son aller-retour.
 *
 * C'est aussi la raison pour laquelle un service externe n'obtient pas
 * l'atomicité qu'un module du monolithe obtient. Cette fenêtre est
 * irréductible ; elle est seulement rendue courte et rattrapable.
 *
 * @see docs/04-decisions/adr-0010-external-payment-api.md
 */
final class NotifyExternalProduct
{
    public const SUCCEEDED = 'payment.succeeded';

    public const FAILED = 'payment.failed';

    public const REFUNDED = 'refund.succeeded';

    public const REFUND_FAILED = 'refund.failed';

    /**
     * L'issue d'un remboursement.
     *
     * Charge utile distincte de celle d'un encaissement : sur un remboursement
     * **partiel**, le montant rendu n'est pas celui de la charge, et réutiliser
     * la même structure ferait croire au produit qu'il a tout rendu.
     */
    public function refundOutcome(ExternalCharge $charge, RefundSettlement $settlement): void
    {
        $this->deliver(
            $charge,
            $settlement->succeeded ? self::REFUNDED : self::REFUND_FAILED,
            [
                'refund_id' => $settlement->refundId,
                'charge_id' => $charge->id,
                'payment_id' => $settlement->paymentIntentId,
                'subject_type' => $charge->subject_type,
                'subject_id' => $charge->subject_id,

                // Ce qui a été rendu, pas ce qui avait été encaissé.
                ...$settlement->amount->toArray(),

                // Comment le produit sait qu'il lui reste quelque chose à
                // rendre, sans avoir à tenir sa propre comptabilité.
                'charge_amount' => $charge->amount,

                'status' => $settlement->succeeded ? 'succeeded' : 'failed',
                'failure_code' => $settlement->failureCode,
            ],
        );
    }

    public function handle(ExternalCharge $charge, string $eventType): void
    {
        $this->deliver($charge, $eventType, $this->paymentData($charge, $eventType));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function deliver(ExternalCharge $charge, string $eventType, array $data): void
    {
        $endpoint = PaymentEndpoint::query()
            ->where('organization_id', $charge->organization_id)
            ->first();

        if ($endpoint === null) {
            // Un produit sans destination n'est pas une erreur d'exécution : il
            // lui reste le sondage et la réconciliation, tous deux obligatoires
            // par contrat. Le taire complètement, en revanche, laisserait une
            // intégration à moitié faite passer pour terminée.
            Log::warning('Issue de paiement sans endpoint de livraison.', [
                'organization_id' => $charge->organization_id,
                'external_charge_id' => $charge->id,
                'event_type' => $eventType,
            ]);

            return;
        }

        // Stable d'un réessai à l'autre : c'est la clé sur laquelle le produit
        // déduplique, et une clé qui changerait à chaque envoi rendrait la
        // déduplication impossible.
        $eventId = 'evt_'.Str::lower((string) Str::ulid());

        $delivery = PaymentDelivery::create([
            'payment_endpoint_id' => $endpoint->id,
            'event_id' => $eventId,
            'event_type' => $eventType,
            'payment_intent_id' => $charge->payment_intent_id,
            'payload' => $this->envelope($eventId, $eventType, $data),
            'status' => PaymentDelivery::PENDING,
        ]);

        // `afterCommit` : sans lui, un worker peut lire la ligne avant que la
        // transaction d'encaissement ne soit validée, et livrer l'issue d'un
        // paiement que la base ne connaît pas encore.
        DeliverPaymentEvent::dispatch($delivery->id)->afterCommit();
    }

    /**
     * L'enveloppe est identique quel que soit l'événement : un intégrateur
     * n'apprend qu'une seule forme.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function envelope(string $eventId, string $eventType, array $data): array
    {
        return [
            'id' => $eventId,
            'type' => $eventType,
            'occurred_at' => now()->toIso8601ZuluString(),
            'request_id' => RequestId::current(),
            'data' => $data,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function paymentData(ExternalCharge $charge, string $eventType): array
    {
        return [
            'charge_id' => $charge->id,
            'payment_id' => $charge->payment_intent_id,
            'subject_type' => $charge->subject_type,
            'subject_id' => $charge->subject_id,
            'payer_type' => $charge->payer_type,
            'payer_id' => $charge->payer_id,

            // Le montant de la charge déclarée, jamais celui rapporté par
            // l'agrégateur : ce dernier est un constat, pas une autorité.
            ...$charge->money()->toArray(),

            'status' => $eventType === self::SUCCEEDED
                ? ExternalCharge::PAID
                : ExternalCharge::FAILED,
        ];
    }
}
