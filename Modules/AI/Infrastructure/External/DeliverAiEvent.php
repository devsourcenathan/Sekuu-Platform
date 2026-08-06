<?php

declare(strict_types=1);

namespace Modules\AI\Infrastructure\External;

use App\Platform\Support\SignedWebhook;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Modules\AI\Domain\Models\AiDelivery;
use Modules\AI\Domain\Models\AiEndpoint;
use Throwable;

/**
 * Livre l'issue d'une génération au produit qui l'a demandée.
 *
 * ## Le webhook n'est pas la garantie, et ici moins qu'ailleurs
 *
 * Côté paiement, une livraison perdue se rattrape par la réconciliation :
 * l'argent existe quelque part, et on peut le retrouver chez l'agrégateur.
 *
 * Une génération perdue, elle, **a déjà coûté et n'est nulle part ailleurs**.
 * Si le produit ne la lit pas, il paiera pour la relancer. C'est pourquoi le
 * sondage n'est pas un filet mais la voie normale, et pourquoi la sortie
 * survit à l'appel au lieu de ne vivre que dans cette charge utile.
 *
 * ## Ce que la charge utile ne porte jamais
 *
 * **Ni le prompt, ni la sortie.** Un webhook traverse un réseau vers une URL
 * que le produit a déclarée ; y mettre le contenu d'une génération reviendrait
 * à publier ce que l'ADR-0016 refuse de stocker. Le produit apprend qu'une
 * sortie l'attend, et vient la chercher authentifié.
 *
 * ## Cadence des réessais
 *
 * 1 min, 5 min, 30 min, 2 h, 6 h — la même que Notify et Payments. Une seule
 * cadence pour toute la plateforme : deux barèmes différents pour le même
 * problème n'apprendraient rien de plus à personne.
 *
 * @see docs/03-services/ai/07-external-api.md
 */
final class DeliverAiEvent implements ShouldQueue
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
        $delivery = AiDelivery::query()->with('endpoint')->find($this->deliveryId);

        if ($delivery === null || $delivery->status !== AiDelivery::PENDING) {
            return;
        }

        $endpoint = $delivery->endpoint;

        if ($endpoint === null || $endpoint->status !== AiEndpoint::ACTIVE) {
            // Suspendu : la livraison reste `pending` et repartira. Elle n'est
            // ni perdue ni consommée.
            return;
        }

        SignedWebhook::assertTestSafeHost($endpoint->url);

        // Sérialisé **une seule fois**, et signé tel quel. Signer une
        // représentation puis en envoyer une autre produit une signature que le
        // produit ne peut pas reproduire.
        $body = (string) json_encode($delivery->payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $delivery->increment('attempts');

        try {
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'X-Sekuu-Signature' => SignedWebhook::signature($endpoint->signingSecrets(), $body),
                'X-Sekuu-Event-Id' => $delivery->event_id,
                'X-Sekuu-Delivery-Attempt' => (string) $delivery->attempts,
            ])
                ->timeout((int) config('ai.delivery_timeout', 10))
                ->withBody($body, 'application/json')
                ->post($endpoint->url);
        } catch (Throwable $exception) {
            $this->recordFailure($delivery, null, $exception->getMessage());

            throw $exception;
        }

        if ($response->successful()) {
            $delivery->forceFill([
                'status' => AiDelivery::DELIVERED,
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
     * produit transformerait alors une interruption en silence permanent, et il
     * faudrait qu'un humain s'en aperçoive pour le rouvrir. La livraison est
     * marquée `exhausted`, elle reste visible, et le sondage rattrape.
     */
    public function failed(Throwable $exception): void
    {
        $delivery = AiDelivery::query()->find($this->deliveryId);

        if ($delivery === null || $delivery->status !== AiDelivery::PENDING) {
            return;
        }

        $delivery->forceFill([
            'status' => AiDelivery::EXHAUSTED,
            'last_error' => mb_substr($exception->getMessage(), 0, 500),
        ])->save();

        // Moins grave qu'un paiement non annoncé — le produit peut sonder — mais
        // une sortie qu'il ne vient jamais chercher a été payée pour rien.
        Log::warning('Livraison d\'IA abandonnée après tous les réessais.', [
            'delivery_id' => $delivery->id,
            'event_id' => $delivery->event_id,
            'event_type' => $delivery->event_type,
            'generation_id' => $delivery->generation_id,
            'attempts' => $delivery->attempts,
        ]);
    }

    private function recordFailure(AiDelivery $delivery, ?int $status, string $error): void
    {
        $delivery->forceFill([
            'last_status_code' => $status,
            'last_error' => mb_substr($error, 0, 500),
        ])->save();
    }
}
