<?php

declare(strict_types=1);

namespace Modules\Payments\Application\External;

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

    public function handle(ExternalCharge $charge, string $eventType): void
    {
        $endpoint = PaymentEndpoint::query()
            ->where('organization_id', $charge->organization_id)
            ->first();

        if ($endpoint === null) {
            // Un produit sans destination n'est pas une erreur d'exécution : il
            // lui reste le sondage et la réconciliation, tous deux obligatoires
            // par contrat. Le taire complètement, en revanche, laisserait une
            // intégration à moitié faite passer pour terminée.
            Log::warning('Encaissement externe sans endpoint de livraison.', [
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
            'payload' => $this->payload($eventId, $charge, $eventType),
            'status' => PaymentDelivery::PENDING,
        ]);

        // `afterCommit` : sans lui, un worker peut lire la ligne avant que la
        // transaction d'encaissement ne soit validée, et livrer l'issue d'un
        // paiement que la base ne connaît pas encore.
        DeliverPaymentEvent::dispatch($delivery->id)->afterCommit();
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(string $eventId, ExternalCharge $charge, string $eventType): array
    {
        return [
            'id' => $eventId,
            'type' => $eventType,
            'occurred_at' => now()->toIso8601ZuluString(),
            'request_id' => RequestId::current(),
            'data' => [
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
            ],
        ];
    }
}
