<?php

declare(strict_types=1);

namespace Modules\Notify\Tests\Feature;

use App\Platform\Exceptions\DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Modules\Notify\Application\Sending\SendNotification;
use Modules\Notify\Application\Sending\SendRequest;
use Modules\Notify\Domain\Models\Notification;
use Modules\Notify\Domain\Models\NotificationEvent;
use Modules\Notify\Domain\Models\Suppression;
use Tests\TestCase;

/**
 * @see docs/03-services/notify/03-api.md
 */
final class ResendWebhookTest extends TestCase
{
    use RefreshDatabase;

    /** Secret au format Svix : préfixe `whsec_` suivi de la clé en base64. */
    private const SECRET = 'whsec_c2VrdXUtc2VjcmV0LWRlLXRlc3Q=';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'notify.email.resend.webhook_secret' => self::SECRET,
            'notify.email.resend.api_key' => 're_cle_de_test',
        ]);
    }

    public function test_an_unsigned_webhook_is_refused(): void
    {
        $this->postJson('/api/v1/webhooks/resend', ['type' => 'email.delivered'])
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'WEBHOOK_SIGNATURE_INVALID');
    }

    public function test_a_forged_signature_is_refused(): void
    {
        $this->deliver(['type' => 'email.delivered', 'data' => []], signature: 'v1,signature-fabriquee')
            ->assertStatus(401);
    }

    /**
     * Sans secret configuré, le endpoint doit être **fermé**, pas ouvert : une
     * configuration incomplète ne doit jamais dégrader en absence de contrôle.
     */
    public function test_a_missing_secret_closes_the_endpoint(): void
    {
        config(['notify.email.resend.webhook_secret' => null]);

        $this->deliver(['type' => 'email.delivered', 'data' => []])->assertStatus(401);
    }

    /**
     * Sans fenêtre temporelle, une signature valide capturée resterait
     * rejouable indéfiniment.
     */
    public function test_an_old_signature_is_refused(): void
    {
        $this->deliver(
            ['type' => 'email.delivered', 'data' => []],
            timestamp: now()->subHour()->timestamp,
        )->assertStatus(401);
    }

    /**
     * Pendant une rotation de clé, Resend envoie plusieurs signatures : il
     * suffit qu'une seule corresponde.
     */
    public function test_a_multi_signature_header_is_accepted(): void
    {
        $notification = $this->sendAndAccept();
        $payload = $this->deliveredPayload($notification);
        $body = (string) json_encode($payload);
        $id = 'msg_multi';
        $timestamp = now()->timestamp;

        $valid = $this->signature($id, $timestamp, $body);

        $this->postRaw($body, $id, $timestamp, 'v1,ancienne-signature '.$valid)->assertOk();
    }

    public function test_a_delivery_confirmation_updates_the_status(): void
    {
        $notification = $this->sendAndAccept();

        $this->assertSame(Notification::SENT, $notification->status);

        $this->deliver($this->deliveredPayload($notification))
            ->assertOk()
            ->assertJsonPath('data.processed', 1);

        // `sent` signifiait « accepté par le fournisseur » ; c'est seulement
        // maintenant qu'on sait que le message est arrivé.
        $this->assertSame(Notification::DELIVERED, $notification->fresh()->status);
    }

    /**
     * L'identifiant Svix est unique par livraison : c'est lui qui absorbe les
     * rejeux, portés par l'index unique.
     */
    public function test_a_replayed_event_is_absorbed(): void
    {
        $notification = $this->sendAndAccept();
        $payload = $this->deliveredPayload($notification);

        $this->deliver($payload, id: 'msg_identique')->assertJsonPath('data.processed', 1);
        $this->deliver($payload, id: 'msg_identique')->assertJsonPath('data.processed', 0);

        $this->assertSame(1, NotificationEvent::query()->count());
    }

    /**
     * Le cœur du dispositif : c'est le seul mécanisme qui alimente la liste de
     * suppression sans intervention humaine.
     */
    public function test_a_permanent_bounce_suppresses_the_address(): void
    {
        $notification = $this->sendAndAccept();

        $this->deliver([
            'type' => 'email.bounced',
            'created_at' => now()->toIso8601ZuluString(),
            'data' => [
                'email_id' => $notification->deliveries()->firstOrFail()->provider_message_id,
                'to' => ['nathan@sekuu.com'],
                'bounce' => ['type' => 'Permanent', 'subType' => 'General'],
            ],
        ])->assertOk();

        $this->assertDatabaseHas('suppressions', [
            'channel' => 'email',
            'destination' => 'nathan@sekuu.com',
            'reason' => Suppression::HARD_BOUNCE,
            'source' => 'resend',
        ]);

        $this->assertSame(Notification::FAILED, $notification->fresh()->status);
    }

    /**
     * Une boîte pleine se videra peut-être : supprimer l'adresse serait
     * excessif.
     */
    public function test_a_transient_bounce_does_not_suppress(): void
    {
        $notification = $this->sendAndAccept();

        $this->deliver([
            'type' => 'email.bounced',
            'data' => [
                'email_id' => $notification->deliveries()->firstOrFail()->provider_message_id,
                'to' => ['nathan@sekuu.com'],
                'bounce' => ['type' => 'Transient', 'subType' => 'MailboxFull'],
            ],
        ])->assertOk();

        $this->assertDatabaseCount('suppressions', 0);
    }

    public function test_a_complaint_suppresses_the_address(): void
    {
        $notification = $this->sendAndAccept();

        $this->deliver([
            'type' => 'email.complained',
            'data' => [
                'email_id' => $notification->deliveries()->firstOrFail()->provider_message_id,
                'to' => ['nathan@sekuu.com'],
            ],
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

        $this->deliver([
            'type' => 'email.bounced',
            'data' => [
                'email_id' => $notification->deliveries()->firstOrFail()->provider_message_id,
                'to' => ['nathan@sekuu.com'],
                'bounce' => ['type' => 'Permanent'],
            ],
        ])->assertOk();

        $this->expectException(DomainException::class);

        $this->app->make(SendNotification::class)->handle(SendRequest::toEmail(
            templateKey: 'password.reset',
            email: 'nathan@sekuu.com',
            variables: $this->resetVariables(),
        ));
    }

    /**
     * `email.sent` n'apprend rien de plus que la réponse d'envoi, et
     * `email.delivery_delayed` est un retard, pas un échec.
     */
    public function test_uninteresting_events_are_ignored_with_a_200(): void
    {
        $notification = $this->sendAndAccept();
        $messageId = $notification->deliveries()->firstOrFail()->provider_message_id;

        foreach (['email.sent', 'email.delivery_delayed', 'email.opened'] as $type) {
            $this->deliver(['type' => $type, 'data' => ['email_id' => $messageId]])
                ->assertOk()
                ->assertJsonPath('data.processed', 0);
        }

        $this->assertSame(0, NotificationEvent::query()->count());
        $this->assertSame(Notification::SENT, $notification->fresh()->status);
    }

    // ------------------------------------------------------------ fixtures --

    private function signature(string $id, int $timestamp, string $body): string
    {
        $key = base64_decode(substr(self::SECRET, 6), true);

        return 'v1,'.base64_encode(hash_hmac('sha256', $id.'.'.$timestamp.'.'.$body, $key, true));
    }

    private function deliver(
        array $payload,
        string $id = 'msg_1',
        ?int $timestamp = null,
        ?string $signature = null,
    ): TestResponse {
        $body = (string) json_encode($payload);
        $timestamp ??= now()->timestamp;

        return $this->postRaw(
            $body,
            $id,
            $timestamp,
            $signature ?? $this->signature($id, $timestamp, $body),
        );
    }

    private function postRaw(string $body, string $id, int $timestamp, string $signature): TestResponse
    {
        return $this->call(
            'POST',
            '/api/v1/webhooks/resend',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_SVIX_ID' => $id,
                'HTTP_SVIX_TIMESTAMP' => (string) $timestamp,
                'HTTP_SVIX_SIGNATURE' => $signature,
            ],
            content: $body,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function deliveredPayload(Notification $notification): array
    {
        return [
            'type' => 'email.delivered',
            'created_at' => now()->toIso8601ZuluString(),
            'data' => [
                'email_id' => $notification->deliveries()->firstOrFail()->provider_message_id,
                'to' => ['nathan@sekuu.com'],
            ],
        ];
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
        Http::fake(['api.resend.com/*' => Http::response(['id' => 're-msg-1'], 200)]);

        return $this->app->make(SendNotification::class)->handle(SendRequest::toEmail(
            templateKey: 'password.reset',
            email: 'nathan@sekuu.com',
            variables: $this->resetVariables(),
        ))->first()->fresh();
    }
}
