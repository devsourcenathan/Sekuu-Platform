<?php

declare(strict_types=1);

namespace Modules\Payments\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Modules\Payments\Domain\Models\PaymentDelivery;
use Modules\Payments\Domain\Models\PaymentEndpoint;
use Modules\Payments\Infrastructure\External\DeliverPaymentEvent;
use Modules\Payments\Infrastructure\External\DeliveryRefused;
use Tests\TestCase;

/**
 * La livraison de l'issue à un produit externe.
 *
 * Le webhook n'est **pas** la garantie : il est l'accélérateur. C'est la même
 * règle que celle appliquée aux agrégateurs, dans l'autre sens — Payments ne
 * croit pas leurs callbacks, il ne demande donc pas qu'on croie les siens.
 *
 * @see docs/03-services/payments/07-external-api.md
 */
final class OutboundDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://learn.example.test/webhooks/payments';

    /**
     * La signature porte sur le corps **brut**, celui qui a réellement transité.
     */
    public function test_the_body_is_signed_with_the_current_secret(): void
    {
        Http::fake([self::URL => Http::response('', 200)]);

        $endpoint = $this->endpoint();
        $delivery = $this->delivery($endpoint);

        $this->app->make(DeliverPaymentEvent::class, ['deliveryId' => $delivery->id])->handle();

        Http::assertSent(function (Request $request) use ($endpoint): bool {
            $attendu = 'v1='.hash_hmac('sha256', $request->body(), $endpoint->secret);

            return $request->header('X-Sekuu-Signature')[0] === $attendu;
        });

        $delivery->refresh();

        $this->assertSame(PaymentDelivery::DELIVERED, $delivery->status);
        $this->assertSame(200, $delivery->last_status_code);
        $this->assertNotNull($delivery->delivered_at);
    }

    /**
     * Pendant une rotation, la livraison porte **les deux** signatures.
     *
     * Une coupure nette aurait été plus simple à écrire, et aurait fait échouer
     * toutes les livraisons d'un produit qui déploie une heure plus tard —
     * c'est-à-dire des clients payés sans service.
     */
    public function test_a_rotation_window_signs_with_both_secrets(): void
    {
        Http::fake([self::URL => Http::response('', 200)]);

        $endpoint = $this->endpoint();
        $endpoint->forceFill([
            'previous_secret' => 'whsec_ancien',
            'previous_secret_expires_at' => now()->addHours(48),
        ])->save();

        $this->app->make(DeliverPaymentEvent::class, ['deliveryId' => $this->delivery($endpoint)->id])->handle();

        Http::assertSent(function (Request $request) use ($endpoint): bool {
            $signatures = explode(',', $request->header('X-Sekuu-Signature')[0]);

            return count($signatures) === 2
                && $signatures[0] === 'v1='.hash_hmac('sha256', $request->body(), $endpoint->secret)
                && $signatures[1] === 'v1='.hash_hmac('sha256', $request->body(), 'whsec_ancien');
        });
    }

    /**
     * La fenêtre se referme d'elle-même : passé le délai, l'ancien secret ne
     * signe plus. Un secret qui resterait valide indéfiniment ne serait pas une
     * rotation.
     */
    public function test_an_expired_previous_secret_no_longer_signs(): void
    {
        Http::fake([self::URL => Http::response('', 200)]);

        $endpoint = $this->endpoint();
        $endpoint->forceFill([
            'previous_secret' => 'whsec_ancien',
            'previous_secret_expires_at' => now()->subMinute(),
        ])->save();

        $this->app->make(DeliverPaymentEvent::class, ['deliveryId' => $this->delivery($endpoint)->id])->handle();

        Http::assertSent(
            fn (Request $request): bool => ! str_contains($request->header('X-Sekuu-Signature')[0], ',')
        );
    }

    /**
     * Une réponse qui n'est pas `2xx` lève : c'est ce qui fait réessayer la
     * file. Un échec silencieux serait un encaissement que le produit n'apprend
     * jamais.
     */
    public function test_a_refusal_is_recorded_and_rethrown(): void
    {
        Http::fake([self::URL => Http::response('nope', 500)]);

        $delivery = $this->delivery($this->endpoint());

        try {
            $this->app->make(DeliverPaymentEvent::class, ['deliveryId' => $delivery->id])->handle();
            $this->fail('Une réponse 500 doit lever.');
        } catch (DeliveryRefused) {
            // attendu
        }

        $delivery->refresh();

        $this->assertSame(PaymentDelivery::PENDING, $delivery->status);
        $this->assertSame(1, $delivery->attempts);
        $this->assertSame(500, $delivery->last_status_code);
    }

    /**
     * Tous les réessais consommés : la livraison est marquée, **l'endpoint
     * n'est pas désactivé**.
     *
     * Le désactiver transformerait une panne de quelques heures en silence
     * permanent, qu'il faudrait qu'un humain remarque pour le rouvrir.
     */
    public function test_exhausting_the_retries_never_disables_the_endpoint(): void
    {
        $endpoint = $this->endpoint();
        $delivery = $this->delivery($endpoint);

        $this->app->make(DeliverPaymentEvent::class, ['deliveryId' => $delivery->id])
            ->failed(new DeliveryRefused('injoignable'));

        $this->assertSame(PaymentDelivery::EXHAUSTED, $delivery->fresh()->status);
        $this->assertSame(PaymentEndpoint::ACTIVE, $endpoint->fresh()->status);
    }

    /**
     * Un endpoint suspendu n'engloutit pas ses livraisons : elles restent en
     * attente et repartiront.
     */
    public function test_a_paused_endpoint_holds_its_deliveries(): void
    {
        Http::fake();

        $endpoint = $this->endpoint();
        $endpoint->forceFill(['status' => PaymentEndpoint::PAUSED])->save();

        $delivery = $this->delivery($endpoint);

        $this->app->make(DeliverPaymentEvent::class, ['deliveryId' => $delivery->id])->handle();

        Http::assertNothingSent();
        $this->assertSame(PaymentDelivery::PENDING, $delivery->fresh()->status);
    }

    /**
     * Une livraison déjà remise n'est pas rejouée : la file peut dupliquer une
     * tâche, le produit ne doit pas en subir les conséquences.
     */
    public function test_an_already_delivered_event_is_not_sent_twice(): void
    {
        Http::fake();

        $delivery = $this->delivery($this->endpoint());
        $delivery->forceFill(['status' => PaymentDelivery::DELIVERED])->save();

        $this->app->make(DeliverPaymentEvent::class, ['deliveryId' => $delivery->id])->handle();

        Http::assertNothingSent();
    }

    private function endpoint(): PaymentEndpoint
    {
        return PaymentEndpoint::create([
            'organization_id' => (string) Str::uuid(),
            'url' => self::URL,
            'secret' => 'whsec_courant',
            'status' => PaymentEndpoint::ACTIVE,
        ]);
    }

    private function delivery(PaymentEndpoint $endpoint): PaymentDelivery
    {
        $eventId = 'evt_'.Str::lower((string) Str::ulid());

        return PaymentDelivery::create([
            'payment_endpoint_id' => $endpoint->id,
            'event_id' => $eventId,
            'event_type' => 'payment.succeeded',
            'payment_intent_id' => (string) Str::uuid(),
            'payload' => [
                'id' => $eventId,
                'type' => 'payment.succeeded',
                'data' => ['amount' => 15000, 'currency' => 'XAF'],
            ],
            'status' => PaymentDelivery::PENDING,
        ]);
    }
}
