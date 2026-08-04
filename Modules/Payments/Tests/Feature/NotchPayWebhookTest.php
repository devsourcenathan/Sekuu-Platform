<?php

declare(strict_types=1);

namespace Modules\Payments\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Modules\Payments\Infrastructure\Webhooks\NotchPayWebhookHandler;
use Tests\TestCase;

/**
 * Notch Pay signe réellement ses callbacks — HMAC-SHA256 sur le corps brut.
 *
 * C'est une garantie que le secret partagé de Tranzak n'offre pas : un callback
 * modifié en transit est détectable. Le rejeu à l'identique reste possible, et
 * c'est la contrainte d'unicité en base qui le neutralise.
 *
 * @see docs/03-services/payments/05-providers.md
 */
final class NotchPayWebhookTest extends TestCase
{
    use RefreshDatabase;

    private NotchPayWebhookHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('payments.notchpay.webhook_hash', 'secret-de-signature');

        $this->handler = new NotchPayWebhookHandler;
    }

    public function test_a_valid_signature_is_accepted(): void
    {
        $body = json_encode(['type' => 'payment.complete', 'data' => ['id' => 'trx-1']]);

        $this->assertTrue($this->handler->verify($this->request($body, $this->sign($body))));
    }

    public function test_a_tampered_body_is_refused(): void
    {
        $body = json_encode(['type' => 'payment.complete', 'data' => ['id' => 'trx-1']]);
        $signature = $this->sign($body);

        $tampered = json_encode(['type' => 'payment.complete', 'data' => ['id' => 'trx-2']]);

        // Ce que le HMAC apporte et que le secret partagé de Tranzak ne peut
        // pas : détecter une modification en transit.
        $this->assertFalse($this->handler->verify($this->request($tampered, $signature)));
    }

    public function test_a_missing_signature_is_refused(): void
    {
        $body = json_encode(['type' => 'payment.complete']);

        $this->assertFalse($this->handler->verify($this->request($body, null)));
    }

    /**
     * Sans secret configuré, aucun callback n'est accepté — pas même un
     * légitime. Accepter par défaut ferait d'une variable d'environnement
     * oubliée une porte ouverte sur les paiements.
     */
    public function test_an_unconfigured_secret_closes_the_endpoint(): void
    {
        config()->set('payments.notchpay.webhook_hash', null);

        $body = json_encode(['type' => 'payment.complete']);

        $this->assertFalse($this->handler->verify($this->request($body, $this->sign($body))));
    }

    /**
     * Corps **réel** d'un callback, capturé sur le bac à sable.
     *
     * La documentation annonce `type` et `data.id` ; Notch Pay envoie `event`
     * et un `id` de premier niveau. La première version retombait donc
     * toujours sur l'empreinte du corps — ce qui marchait par accident, mais
     * aurait laissé passer deux fois un **renvoi** de la même livraison.
     */
    public function test_the_event_id_is_the_delivery_id(): void
    {
        $body = json_encode($this->realPayload());

        $this->assertSame(
            'whc_test.RBbtPFQbBiIXebt7',
            $this->handler->eventId($this->request($body, null)),
        );
    }

    public function test_the_reference_is_read_from_a_real_payload(): void
    {
        $body = json_encode($this->realPayload());

        $this->assertSame(
            'trx.test_xRV6AHATJGClY8RGngx3efVK',
            $this->handler->providerRef($this->request($body, null)),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function realPayload(): array
    {
        return [
            'id' => 'whc_test.RBbtPFQbBiIXebt7',
            'event' => 'payment.complete',
            'data' => [
                'fees' => [],
                'amount' => 100,
                'charge' => 'business',
                'status' => 'complete',
                'trxref' => 'SKU57352EA63744B5D4',
                'amounts' => ['rate' => 1, 'total' => 100, 'currency' => 'XAF', 'converted' => 100],
                'sandbox' => 1,
                'currency' => 'XAF',
                'reference' => 'trx.test_xRV6AHATJGClY8RGngx3efVK',
                'merchant_reference' => 'SKU57352EA63744B5D4',
            ],
        ];
    }

    /**
     * Sans identifiant exploitable, l'empreinte du corps sert de clé : deux
     * callbacks strictement identiques restent dédupliqués.
     */
    public function test_an_event_without_an_id_still_deduplicates(): void
    {
        $body = json_encode(['type' => 'payment.complete']);

        $first = $this->handler->eventId($this->request($body, null));
        $second = $this->handler->eventId($this->request($body, null));

        $this->assertSame($first, $second);
        $this->assertStringStartsWith('payment.complete:', $first);
    }

    public function test_the_reference_is_extracted_from_the_payload(): void
    {
        $body = json_encode(['event' => 'payment.complete', 'data' => ['reference' => 'trx-9']]);

        $this->assertSame('trx-9', $this->handler->providerRef($this->request($body, null)));
    }

    /**
     * Notch Pay a envoyé **trois** livraisons pour un seul paiement, dans
     * l'ordre `processing`, `pending`, `complete` — un `pending` arrivé
     * **après** un `complete`.
     *
     * C'est la démonstration en conditions réelles de la règle « le corps ne
     * décide jamais de l'issue » : croire ce statut aurait fait régresser un
     * paiement encaissé vers « en attente ».
     */
    public function test_three_deliveries_of_one_payment_have_distinct_ids(): void
    {
        $ids = [];

        foreach (['processing', 'pending', 'complete'] as $index => $status) {
            $payload = $this->realPayload();
            $payload['id'] = 'whc_test.delivery'.$index;
            $payload['data']['status'] = $status;

            $ids[] = $this->handler->eventId($this->request((string) json_encode($payload), null));
        }

        $this->assertCount(3, array_unique($ids));
    }

    private function sign(string $body): string
    {
        return hash_hmac('sha256', $body, 'secret-de-signature');
    }

    private function request(string $body, ?string $signature): Request
    {
        $headers = ['CONTENT_TYPE' => 'application/json'];

        if ($signature !== null) {
            $headers['HTTP_X_NOTCH_SIGNATURE'] = $signature;
        }

        return Request::create('/api/v1/billing/webhooks/notchpay', 'POST', [], [], [], $headers, $body);
    }
}
