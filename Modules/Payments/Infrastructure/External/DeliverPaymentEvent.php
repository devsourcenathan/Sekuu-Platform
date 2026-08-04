<?php

declare(strict_types=1);

namespace Modules\Payments\Infrastructure\External;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use LogicException;
use Modules\Payments\Domain\Models\PaymentDelivery;
use Modules\Payments\Domain\Models\PaymentEndpoint;
use Throwable;

/**
 * Livre l'issue d'un paiement au produit qui l'a déclaré.
 *
 * ## Le webhook n'est pas la garantie
 *
 * Il est l'accélérateur. Un produit qui ne met en place que cela aura, tôt ou
 * tard, un client payé sans service : le sondage et la réconciliation restent
 * obligatoires par contrat.
 *
 * C'est la même règle que celle appliquée aux agrégateurs, dans l'autre sens.
 * Payments ne croit pas leurs callbacks ; il ne demande donc pas qu'on croie
 * les siens.
 *
 * ## Cadence des réessais
 *
 * 1 min, 5 min, 30 min, 2 h, 6 h — la même que Notify. Une seule cadence pour
 * toute la plateforme : deux barèmes différents pour le même problème
 * n'apprendraient rien de plus à personne.
 *
 * @see docs/03-services/payments/07-external-api.md
 */
final class DeliverPaymentEvent implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** 1 min, 5 min, 30 min, 2 h, 6 h. */
    public array $backoff = [60, 300, 1800, 7200, 21600];

    public int $tries = 6;

    public function __construct(public readonly string $deliveryId) {}

    public function uniqueId(): string
    {
        return $this->deliveryId;
    }

    public function handle(): void
    {
        $delivery = PaymentDelivery::query()->with('endpoint')->find($this->deliveryId);

        if ($delivery === null || $delivery->status !== PaymentDelivery::PENDING) {
            return;
        }

        $endpoint = $delivery->endpoint;

        if ($endpoint === null || $endpoint->status !== PaymentEndpoint::ACTIVE) {
            // Suspendu : la livraison reste `pending` et repartira. Elle n'est
            // ni perdue ni consommée.
            return;
        }

        $this->refuseRealHostDuringTests($endpoint->url);

        // Sérialisé **une seule fois**, et signé tel quel. Signer une
        // représentation puis en envoyer une autre — un tableau réordonné, un
        // espace de plus — produit une signature que le produit ne peut pas
        // reproduire.
        $body = json_encode($delivery->payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $delivery->increment('attempts');

        try {
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'X-Sekuu-Signature' => $this->signature($endpoint, (string) $body),
                'X-Sekuu-Event-Id' => $delivery->event_id,
                'X-Sekuu-Delivery-Attempt' => (string) $delivery->attempts,
            ])
                ->timeout((int) config('payments.external.delivery_timeout', 10))
                ->withBody((string) $body, 'application/json')
                ->post($endpoint->url);
        } catch (Throwable $exception) {
            $this->recordFailure($delivery, null, $exception->getMessage());

            throw $exception;
        }

        if ($response->successful()) {
            $delivery->forceFill([
                'status' => PaymentDelivery::DELIVERED,
                'last_status_code' => $response->status(),
                'last_error' => null,
                'delivered_at' => now(),
            ])->save();

            return;
        }

        $this->recordFailure($delivery, $response->status(), mb_substr($response->body(), 0, 500));

        throw new DeliveryRefused(
            "Le produit a répondu {$response->status()} pour {$delivery->event_id}."
        );
    }

    /**
     * Tous les réessais consommés.
     *
     * **L'endpoint n'est pas désactivé.** Une panne de quelques heures chez le
     * produit transformerait alors une interruption en silence permanent — et
     * il faudrait qu'un humain s'en aperçoive pour le rouvrir. La livraison est
     * marquée `exhausted`, elle reste visible, et c'est la réconciliation qui
     * rattrape.
     */
    public function failed(Throwable $exception): void
    {
        $delivery = PaymentDelivery::query()->find($this->deliveryId);

        if ($delivery === null || $delivery->status !== PaymentDelivery::PENDING) {
            return;
        }

        $delivery->forceFill([
            'status' => PaymentDelivery::EXHAUSTED,
            'last_error' => mb_substr($exception->getMessage(), 0, 500),
        ])->save();

        // Un produit qui n'a pas appris un encaissement est un client
        // potentiellement payé sans service. Cela doit être visible.
        Log::error('Livraison de paiement abandonnée après tous les réessais.', [
            'delivery_id' => $delivery->id,
            'event_id' => $delivery->event_id,
            'event_type' => $delivery->event_type,
            'payment_intent_id' => $delivery->payment_intent_id,
            'attempts' => $delivery->attempts,
        ]);
    }

    /**
     * Aucune livraison réelle depuis la suite de tests.
     *
     * Les identifiants des agrégateurs sont neutralisés dans `phpunit.xml` —
     * ici, la destination vient de la base, écrite par le test lui-même. Un
     * `Http::fake()` oublié suffirait donc à faire sortir une requête vers un
     * hôte réel, et l'histoire de ce dépôt dit que cela finit par arriver.
     *
     * La protection est structurelle plutôt que disciplinaire : seuls les
     * domaines réservés aux tests sont acceptés.
     */
    private function refuseRealHostDuringTests(string $url): void
    {
        if (! app()->environment('testing')) {
            return;
        }

        $host = (string) parse_url($url, PHP_URL_HOST);

        $reserve = $host === 'localhost'
            || str_ends_with($host, '.test')
            || str_ends_with($host, '.example')
            || str_ends_with($host, '.invalid')
            || str_ends_with($host, '.localhost');

        if (! $reserve) {
            throw new LogicException(
                "Livraison vers un hôte réel depuis les tests : {$host}. "
                .'Utilisez un domaine réservé (.test, .example) ou Http::fake().'
            );
        }
    }

    /**
     * Signature HMAC-SHA256 sur le corps **brut**.
     *
     * Pendant une rotation, deux signatures sont émises — la nouvelle puis
     * l'ancienne. Le produit accepte celle qu'il connaît, et change de secret
     * quand il veut : aucun message n'est rejeté entre-temps.
     *
     * Le format `v1=…,v1=…` est repris de ce que font les plateformes de
     * paiement, précisément parce qu'un intégrateur l'aura déjà vu ailleurs.
     */
    private function signature(PaymentEndpoint $endpoint, string $body): string
    {
        return implode(',', array_map(
            static fn (string $secret): string => 'v1='.hash_hmac('sha256', $body, $secret),
            $endpoint->signingSecrets(),
        ));
    }

    private function recordFailure(PaymentDelivery $delivery, ?int $status, string $error): void
    {
        $delivery->forceFill([
            'last_status_code' => $status,
            'last_error' => mb_substr($error, 0, 500),
        ])->save();
    }
}
