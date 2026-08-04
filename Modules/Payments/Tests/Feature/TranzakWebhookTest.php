<?php

declare(strict_types=1);

namespace Modules\Billing\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Modules\Billing\Infrastructure\Webhooks\TranzakWebhookHandler;
use Tests\TestCase;

/**
 * Tranzak n'authentifie que par un **secret partagé transporté dans le corps**,
 * pas par une signature. Cela prouve que l'émetteur connaît le secret ; jamais
 * que le corps est intact.
 *
 * Toute la déduplication de cet agrégateur en découle.
 *
 * @see docs/03-services/billing/05-providers.md
 */
final class TranzakWebhookTest extends TestCase
{
    use RefreshDatabase;

    private TranzakWebhookHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('billing.tranzak.auth_key', 'secret-partage');

        $this->handler = new TranzakWebhookHandler;
    }

    public function test_a_valid_shared_secret_is_accepted(): void
    {
        $this->assertTrue($this->handler->verify($this->request($this->realPayload())));
    }

    public function test_a_wrong_secret_is_refused(): void
    {
        $payload = $this->realPayload();
        $payload['authKey'] = 'mauvais';

        $this->assertFalse($this->handler->verify($this->request($payload)));
    }

    /**
     * Sans secret configuré, aucun callback n'est accepté — pas même un
     * légitime. Une variable d'environnement oubliée ne doit pas devenir une
     * porte ouverte sur les paiements.
     */
    public function test_an_unconfigured_secret_closes_the_endpoint(): void
    {
        config()->set('billing.tranzak.auth_key', null);

        $this->assertFalse($this->handler->verify($this->request($this->realPayload())));
    }

    /**
     * **Le point qui distingue Tranzak de Notch Pay.**
     *
     * Le corps réel porte un `webhookId` par livraison, qui serait le choix
     * naturel — c'est celui retenu pour Notch Pay, qui **signe** ses callbacks.
     *
     * Tranzak ne signe pas : un callback capté peut être rejoué avec un
     * `webhookId` forgé. La clé doit donc résister à cela, et ne dépendre que
     * du fait rapporté.
     */
    public function test_a_replay_with_a_forged_delivery_id_yields_the_same_key(): void
    {
        $original = $this->realPayload();

        $replayed = $original;
        $replayed['webhookId'] = 'WH-FORGE-PAR-UN-ATTAQUANT';

        $this->assertSame(
            $this->handler->eventId($this->request($original)),
            $this->handler->eventId($this->request($replayed)),
        );
    }

    public function test_the_event_key_combines_type_and_resource(): void
    {
        $this->assertSame(
            'REQUEST.COMPLETED:REQ2608031551VZY4IDS',
            $this->handler->eventId($this->request($this->realPayload())),
        );
    }

    public function test_the_reference_is_read_from_a_real_payload(): void
    {
        $this->assertSame(
            'REQ2608031551VZY4IDS',
            $this->handler->providerRef($this->request($this->realPayload())),
        );
    }

    /**
     * Corps **réel** d'un callback Tranzak, capturé à travers un tunnel public.
     *
     * Il porte les montants imbriqués dans `merchant` — et `payer.fee` vaut 0
     * là où `merchant.fee` vaut 3. Lire l'un pour l'autre enregistrerait un
     * chiffre faux au registre.
     *
     * @return array<string, mixed>
     */
    private function realPayload(): array
    {
        return [
            'name' => 'Tranzak Payment Notification (TPN)',
            'appId' => 'apx9yf1zy68km0',
            'version' => '1.0',
            'eventType' => 'REQUEST.COMPLETED',
            'webhookId' => 'WHXBE74T9H7BCMHURDMQJG',
            'merchantId' => 'MCH123',
            'resourceId' => 'REQ2608031551VZY4IDS',
            'authKey' => 'secret-partage',
            'resource' => [
                'appId' => 'apx9yf1zy68km0',
                'amount' => 100,
                'status' => 'SUCCESSFUL',
                'payer' => ['fee' => 0, 'amount' => 100, 'netAmountPaid' => 100, 'isGuest' => true],
                'merchant' => ['fee' => 3, 'amount' => 100, 'netAmountReceived' => 97],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function request(array $payload): Request
    {
        return Request::create(
            '/api/v1/billing/webhooks/tranzak',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            (string) json_encode($payload),
        );
    }
}
