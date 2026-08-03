<?php

declare(strict_types=1);

namespace Modules\Billing\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Modules\Billing\Infrastructure\Webhooks\NotchPayWebhookHandler;
use Tests\TestCase;

/**
 * Notch Pay signe réellement ses callbacks — HMAC-SHA256 sur le corps brut.
 *
 * C'est une garantie que le secret partagé de Tranzak n'offre pas : un callback
 * modifié en transit est détectable. Le rejeu à l'identique reste possible, et
 * c'est la contrainte d'unicité en base qui le neutralise.
 *
 * @see docs/03-services/billing/05-providers.md
 */
final class NotchPayWebhookTest extends TestCase
{
    use RefreshDatabase;

    private NotchPayWebhookHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('billing.notchpay.webhook_hash', 'secret-de-signature');

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
        config()->set('billing.notchpay.webhook_hash', null);

        $body = json_encode(['type' => 'payment.complete']);

        $this->assertFalse($this->handler->verify($this->request($body, $this->sign($body))));
    }

    public function test_the_event_id_combines_type_and_payload_id(): void
    {
        $body = json_encode(['type' => 'payment.complete', 'data' => ['id' => 'trx-1']]);

        $this->assertSame('payment.complete:trx-1', $this->handler->eventId($this->request($body, null)));
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
        $body = json_encode(['type' => 'payment.complete', 'data' => ['reference' => 'trx-9']]);

        $this->assertSame('trx-9', $this->handler->providerRef($this->request($body, null)));
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
