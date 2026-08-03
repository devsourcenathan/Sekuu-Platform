<?php

declare(strict_types=1);

namespace Modules\Notify\Tests\Feature;

use App\Platform\Exceptions\DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Notify\Application\Sending\SendNotification;
use Modules\Notify\Application\Sending\SendRequest;
use Modules\Notify\Domain\Models\Notification;
use Modules\Notify\Domain\Models\NotificationEvent;
use Modules\Notify\Domain\Models\Suppression;
use Tests\TestCase;

/**
 * @see docs/03-services/notify/03-api.md
 */
final class WebhookTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'jeton-webhook-postmark';

    protected function setUp(): void
    {
        parent::setUp();

        config(['notify.email.postmark.webhook_token' => self::TOKEN]);
    }

    public function test_an_unsigned_webhook_is_refused(): void
    {
        $this->postJson('/api/v1/webhooks/postmark', ['RecordType' => 'Delivery'])
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'WEBHOOK_SIGNATURE_INVALID');
    }

    public function test_a_wrong_token_is_refused(): void
    {
        $this->postJson(
            '/api/v1/webhooks/postmark',
            ['RecordType' => 'Delivery'],
            ['X-Webhook-Token' => 'mauvais-jeton'],
        )->assertStatus(401);
    }

    /**
     * Sans secret configuré, le webhook doit être **fermé**, pas ouvert :
     * une configuration incomplète ne doit jamais dégrader en absence de
     * contrôle.
     */
    public function test_a_missing_secret_closes_the_endpoint(): void
    {
        config(['notify.email.postmark.webhook_token' => null]);

        $this->postJson('/api/v1/webhooks/postmark', ['RecordType' => 'Delivery'], ['X-Webhook-Token' => ''])
            ->assertStatus(401);
    }

    public function test_an_unknown_provider_is_not_found(): void
    {
        $this->postJson('/api/v1/webhooks/inconnu', [])->assertNotFound();
    }

    public function test_a_delivery_confirmation_updates_the_status(): void
    {
        $notification = $this->sendAndAccept();

        $this->assertSame(Notification::SENT, $notification->status);

        $this->send('postmark', [
            'RecordType' => 'Delivery',
            'MessageID' => $notification->id,
            'ID' => 'evt-1',
            'Recipient' => 'nathan@sekuu.com',
        ])->assertOk()->assertJsonPath('data.processed', 1);

        // `sent` signifiait « accepté par le fournisseur » ; c'est seulement
        // maintenant qu'on sait que le message est arrivé.
        $this->assertSame(Notification::DELIVERED, $notification->fresh()->status);
    }

    /**
     * Les fournisseurs rejouent leurs webhooks : la déduplication est
     * structurelle, portée par l'index unique.
     */
    public function test_a_replayed_event_is_absorbed(): void
    {
        $notification = $this->sendAndAccept();

        $payload = [
            'RecordType' => 'Delivery',
            'MessageID' => $notification->id,
            'ID' => 'evt-identique',
        ];

        $this->send('postmark', $payload)->assertOk()->assertJsonPath('data.processed', 1);
        $this->send('postmark', $payload)->assertOk()->assertJsonPath('data.processed', 0);

        $this->assertSame(1, NotificationEvent::query()->count());
    }

    /**
     * Le cœur du dispositif : c'est le seul mécanisme qui alimente la liste de
     * suppression sans intervention humaine.
     */
    public function test_a_hard_bounce_suppresses_the_address(): void
    {
        $notification = $this->sendAndAccept();

        $this->send('postmark', [
            'RecordType' => 'Bounce',
            'Type' => 'HardBounce',
            'MessageID' => $notification->id,
            'ID' => 'evt-bounce',
            'Email' => 'nathan@sekuu.com',
        ])->assertOk();

        $this->assertDatabaseHas('suppressions', [
            'channel' => 'email',
            'destination' => 'nathan@sekuu.com',
            'reason' => Suppression::HARD_BOUNCE,
        ]);

        $this->assertSame(Notification::FAILED, $notification->fresh()->status);
    }

    /**
     * Une boîte pleine se videra peut-être : supprimer l'adresse serait
     * excessif, le réessai a déjà été fait.
     */
    public function test_a_soft_bounce_does_not_suppress(): void
    {
        $notification = $this->sendAndAccept();

        $this->send('postmark', [
            'RecordType' => 'Bounce',
            'Type' => 'SoftBounce',
            'MessageID' => $notification->id,
            'ID' => 'evt-soft',
            'Email' => 'nathan@sekuu.com',
        ])->assertOk();

        $this->assertDatabaseCount('suppressions', 0);
    }

    public function test_a_spam_complaint_suppresses_the_address(): void
    {
        $notification = $this->sendAndAccept();

        $this->send('postmark', [
            'RecordType' => 'SpamComplaint',
            'MessageID' => $notification->id,
            'ID' => 'evt-spam',
            'Email' => 'nathan@sekuu.com',
        ])->assertOk();

        $this->assertDatabaseHas('suppressions', ['reason' => Suppression::COMPLAINT]);
    }

    /**
     * Une suppression née d'un webhook doit réellement bloquer les envois
     * suivants — sinon tout le dispositif est décoratif.
     */
    public function test_a_suppression_born_from_a_webhook_blocks_the_next_message(): void
    {
        $notification = $this->sendAndAccept();

        $this->send('postmark', [
            'RecordType' => 'Bounce',
            'Type' => 'HardBounce',
            'MessageID' => $notification->id,
            'ID' => 'evt-bounce',
            'Email' => 'nathan@sekuu.com',
        ])->assertOk();

        $this->expectException(DomainException::class);

        $this->app->make(SendNotification::class)->handle(SendRequest::toEmail(
            templateKey: 'password.reset',
            email: 'nathan@sekuu.com',
            variables: $this->resetVariables(),
        ));
    }

    /**
     * Répondre en erreur déclencherait des réessais inutiles chez le
     * fournisseur, et finirait par faire désactiver le endpoint.
     */
    public function test_an_unknown_event_type_still_answers_200(): void
    {
        $this->send('postmark', ['RecordType' => 'Open', 'MessageID' => 'inconnu'])
            ->assertOk()
            ->assertJsonPath('data.processed', 0);
    }

    public function test_an_unmatched_bounce_still_suppresses(): void
    {
        // Le message d'origine a été purgé, mais l'adresse rebondit toujours.
        $this->send('postmark', [
            'RecordType' => 'Bounce',
            'Type' => 'HardBounce',
            'MessageID' => 'message-inconnu',
            'ID' => 'evt-orphelin',
            'Email' => 'disparu@sekuu.com',
        ])->assertOk();

        $this->assertDatabaseHas('suppressions', ['destination' => 'disparu@sekuu.com']);
    }

    // ------------------------------------------------------------ SMS (DLR) --

    public function test_the_sms_gateway_signature_is_verified(): void
    {
        config(['notify.sms.local_gateway.webhook_secret' => 'secret-sms']);

        $payload = ['status' => 'delivered', 'message_id' => 'sms-1', 'event_id' => 'dlr-1'];
        $body = (string) json_encode($payload);
        $timestamp = (string) now()->timestamp;

        $this->call(
            'POST',
            '/api/v1/webhooks/local-gateway',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_X_GATEWAY_SIGNATURE' => $timestamp.','.hash_hmac('sha256', $timestamp.'.'.$body, 'secret-sms'),
            ],
            content: $body,
        )->assertOk();
    }

    /**
     * Sans fenêtre temporelle, une signature valide capturée resterait
     * rejouable indéfiniment.
     */
    public function test_an_old_signature_is_refused(): void
    {
        config(['notify.sms.local_gateway.webhook_secret' => 'secret-sms']);

        $payload = ['status' => 'delivered', 'message_id' => 'sms-1'];
        $body = (string) json_encode($payload);
        $timestamp = (string) now()->subHour()->timestamp;

        $this->call(
            'POST',
            '/api/v1/webhooks/local-gateway',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_X_GATEWAY_SIGNATURE' => $timestamp.','.hash_hmac('sha256', $timestamp.'.'.$body, 'secret-sms'),
            ],
            content: $body,
        )->assertStatus(401);
    }

    // ------------------------------------------------------------ fixtures --

    private function send(string $provider, array $payload)
    {
        return $this->postJson(
            "/api/v1/webhooks/{$provider}",
            $payload,
            ['X-Webhook-Token' => self::TOKEN],
        );
    }

    /**
     * @return array<string, string>
     */
    private function resetVariables(): array
    {
        return [
            'first_name' => 'Nathan',
            'reset_url' => 'https://app.sekuu.com/reset?token=abc',
            'expires_in_hours' => '1',
        ];
    }

    private function sendAndAccept(): Notification
    {
        $notification = $this->app->make(SendNotification::class)->handle(SendRequest::toEmail(
            templateKey: 'password.reset',
            email: 'nathan@sekuu.com',
            variables: $this->resetVariables(),
        ))->first();

        return $notification->fresh();
    }
}
