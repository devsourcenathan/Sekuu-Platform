<?php

declare(strict_types=1);

namespace Modules\Notify\Tests\Feature;

use App\Platform\Exceptions\DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Modules\Notify\Application\Sending\SendNotification;
use Modules\Notify\Application\Sending\SendRequest;
use Modules\Notify\Domain\Channel;
use Modules\Notify\Domain\Models\Notification;
use Modules\Notify\Domain\Models\NotificationPreference;
use Modules\Notify\Domain\Models\Suppression;
use Tests\TestCase;

/**
 * @see docs/03-services/notify/01-overview.md
 */
final class SendNotificationTest extends TestCase
{
    use RefreshDatabase;

    private SendNotification $send;

    protected function setUp(): void
    {
        parent::setUp();

        // Pas de Mail::fake() : il n'enregistre que les Mailables, alors que le
        // fournisseur envoie un message brut. Le transport `array` des tests
        // conserve les messages réellement remis au mailer.
        $this->send = $this->app->make(SendNotification::class);
    }

    private function sentMessages(): Collection
    {
        return collect(Mail::mailer()->getSymfonyTransport()->messages());
    }

    public function test_a_message_is_rendered_and_queued(): void
    {
        $notification = $this->sendReset('nathan@sekuu.com');

        $this->assertSame(Notification::SENT, $notification->fresh()->status);
        $this->assertSame('email', $notification->channel);
        $this->assertSame('transactional', $notification->category);

        $this->assertCount(1, $this->sentMessages());
    }

    /**
     * Le contenu est figé à l'acceptation, pas à l'envoi : un template corrigé
     * pendant l'attente en file ne doit pas changer le message envoyé.
     */
    public function test_the_rendered_content_is_frozen_at_acceptance(): void
    {
        $notification = $this->sendReset('nathan@sekuu.com');

        $this->assertStringContainsString('Nathan', $notification->rendered_body);
        $this->assertStringContainsString('https://app.sekuu.com/reset', $notification->rendered_body);
        $this->assertNotEmpty($notification->rendered_subject);
    }

    public function test_the_locale_falls_back_when_the_requested_one_is_missing(): void
    {
        $french = $this->sendReset('nathan@sekuu.com', locale: 'fr');
        $unknown = $this->sendReset('autre@sekuu.com', locale: 'de');

        $this->assertSame('fr', $french->locale);
        $this->assertSame(config('app.fallback_locale'), $unknown->locale);
    }

    public function test_a_missing_required_variable_is_refused_before_queueing(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Missing required variables');

        $this->send->handle(SendRequest::toEmail(
            templateKey: 'password.reset',
            email: 'nathan@sekuu.com',
            variables: ['first_name' => 'Nathan'], // reset_url absent
        ));
    }

    public function test_an_unknown_template_is_refused(): void
    {
        $this->expectException(DomainException::class);

        $this->send->handle(SendRequest::toEmail('inexistant.template', 'nathan@sekuu.com'));
    }

    public function test_an_invalid_email_is_refused(): void
    {
        try {
            $this->sendReset('pas-une-adresse');
            $this->fail('Un destinataire invalide devait être refusé.');
        } catch (DomainException $e) {
            $this->assertSame('RECIPIENT_INVALID', $e->errorCode);
        }
    }

    /**
     * La livraison des événements est « au moins une fois » : un rejeu ne doit
     * jamais produire un second message.
     */
    public function test_the_same_idempotency_key_never_sends_twice(): void
    {
        $key = (string) Str::uuid();

        $first = $this->sendReset('nathan@sekuu.com', idempotencyKey: $key);
        $second = $this->sendReset('nathan@sekuu.com', idempotencyKey: $key);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Notification::query()->count());
        $this->assertCount(1, $this->sentMessages());
    }

    public function test_a_suppressed_destination_blocks_even_transactional_messages(): void
    {
        $this->suppress('email', 'nathan@sekuu.com', Suppression::HARD_BOUNCE);

        try {
            $this->sendReset('nathan@sekuu.com');
            $this->fail('Une destination supprimée devait bloquer l\'envoi.');
        } catch (DomainException $e) {
            $this->assertSame('RECIPIENT_SUPPRESSED', $e->errorCode);
        }

        $this->assertCount(0, $this->sentMessages());

        // Le journal reste complet : le message est enregistré avec son motif.
        $notification = Notification::query()->firstOrFail();
        $this->assertSame(Notification::SUPPRESSED, $notification->status);
        $this->assertSame('RECIPIENT_SUPPRESSED', $notification->failed_reason);
    }

    public function test_suppression_matching_ignores_case(): void
    {
        $this->suppress('email', 'nathan@sekuu.com', Suppression::COMPLAINT);

        $this->expectException(DomainException::class);

        // Sans normalisation, cette adresse échapperait à la suppression.
        $this->sendReset('Nathan@Sekuu.COM');
    }

    /**
     * Couper un lien de réinitialisation enfermerait l'utilisateur dehors.
     */
    public function test_a_preference_cannot_block_a_transactional_message(): void
    {
        $userId = $this->disablePreference('transactional', Channel::EMAIL);

        $notification = $this->sendReset('nathan@sekuu.com', userId: $userId);

        $this->assertSame(Notification::SENT, $notification->fresh()->status);
        $this->assertCount(1, $this->sentMessages());
    }

    public function test_a_preference_blocks_an_operational_message(): void
    {
        $userId = $this->disablePreference('operational', Channel::EMAIL);

        try {
            $this->send->handle(SendRequest::toEmail(
                templateKey: 'invitation.sent',
                email: 'john@gmail.com',
                variables: [
                    'organization_name' => 'SOS Clinique',
                    'role' => 'member',
                    'accept_url' => 'https://app.sekuu.com/invitations/abc',
                    'expires_at' => '2026-08-10',
                ],
                userId: $userId,
            ));

            $this->fail('La préférence devait bloquer ce message.');
        } catch (DomainException $e) {
            $this->assertSame('RECIPIENT_OPTED_OUT', $e->errorCode);
        }

        $this->assertCount(0, $this->sentMessages());
    }

    /**
     * `payload` est exposé par l'API de consultation : les liens à usage unique
     * n'y ont pas leur place. Ils restent dans le corps rendu.
     */
    public function test_the_stored_payload_never_contains_a_link_or_a_token(): void
    {
        $notification = $this->sendReset('nathan@sekuu.com');

        $encoded = (string) json_encode($notification->payload);

        $this->assertStringNotContainsString('https://app.sekuu.com/reset', $encoded);
        $this->assertArrayNotHasKey('reset_url', $notification->payload);
        $this->assertSame('Nathan', $notification->payload['first_name']);

        $this->assertStringContainsString('https://app.sekuu.com/reset', $notification->rendered_body);
    }

    public function test_a_delivery_attempt_is_recorded(): void
    {
        $notification = $this->sendReset('nathan@sekuu.com');

        $delivery = $notification->deliveries()->firstOrFail();

        $this->assertSame('laravel-mail', $delivery->provider);
        $this->assertSame('accepted', $delivery->status);
        $this->assertNotNull($delivery->sent_at);
    }

    // --------------------------------------------------------- multi-canal --

    /**
     * Une clé peut exister sur plusieurs canaux : l'alerte de sécurité part par
     * email **et** par SMS. Choisir arbitrairement l'un des deux serait un bug
     * silencieux.
     */
    public function test_a_key_present_on_two_channels_fans_out(): void
    {
        Http::fake(['*' => Http::response(['message_id' => 'sms-1'], 200)]);
        config(['notify.sms.local_gateway.endpoint' => 'https://gateway.test/send']);
        config(['notify.sms.local_gateway.token' => 'secret']);

        $outcome = $this->send->handle(new SendRequest(
            templateKey: 'password.changed',
            recipients: [
                Channel::EMAIL => 'nathan@sekuu.com',
                Channel::SMS => '+237690000000',
            ],
            variables: ['first_name' => 'Nathan', 'changed_at' => '2026-08-03'],
        ));

        $this->assertCount(2, $outcome->queued);
        $this->assertNotNull($outcome->forChannel(Channel::EMAIL));
        $this->assertNotNull($outcome->forChannel(Channel::SMS));
    }

    public function test_a_channel_without_a_destination_is_skipped_not_failed(): void
    {
        // Aucun numéro fourni : le canal SMS est simplement inapplicable.
        $outcome = $this->send->handle(SendRequest::toEmail(
            templateKey: 'password.changed',
            email: 'nathan@sekuu.com',
            variables: ['first_name' => 'Nathan', 'changed_at' => '2026-08-03'],
        ));

        $this->assertCount(1, $outcome->queued);
        $this->assertSame('CHANNEL_NOT_AVAILABLE', $outcome->skipped[Channel::SMS] ?? null);
    }

    /**
     * Bloquer un canal ne doit pas empêcher les autres de partir.
     */
    public function test_a_suppressed_phone_does_not_block_the_email(): void
    {
        $this->suppress('sms', '+237690000000', Suppression::HARD_BOUNCE);

        $outcome = $this->send->handle(new SendRequest(
            templateKey: 'password.changed',
            recipients: [
                Channel::EMAIL => 'nathan@sekuu.com',
                Channel::SMS => '+237690000000',
            ],
            variables: ['first_name' => 'Nathan', 'changed_at' => '2026-08-03'],
        ));

        $this->assertCount(1, $outcome->queued);
        $this->assertSame(Channel::EMAIL, $outcome->queued->first()->channel);
        $this->assertCount(1, $outcome->blocked);
        $this->assertSame('RECIPIENT_SUPPRESSED', $outcome->blocked->first()->failed_reason);
    }

    public function test_an_invalid_phone_number_is_refused(): void
    {
        try {
            $this->send->handle(new SendRequest(
                templateKey: 'password.changed',
                // Format national : ambigu entre opérateurs, refusé par les
                // passerelles. E.164 est exigé.
                recipients: [Channel::SMS => '690000000'],
                variables: ['first_name' => 'Nathan', 'changed_at' => '2026-08-03'],
            ));

            $this->fail('Un numéro non E.164 devait être refusé.');
        } catch (DomainException $e) {
            $this->assertSame('RECIPIENT_INVALID', $e->errorCode);
        }
    }

    public function test_an_sms_carries_no_subject(): void
    {
        Http::fake(['*' => Http::response(['message_id' => 'sms-1'], 200)]);
        config(['notify.sms.local_gateway.endpoint' => 'https://gateway.test/send']);
        config(['notify.sms.local_gateway.token' => 'secret']);

        $outcome = $this->send->handle(new SendRequest(
            templateKey: 'password.changed',
            recipients: [Channel::SMS => '+237690000000'],
            variables: ['first_name' => 'Nathan', 'changed_at' => '2026-08-03'],
        ));

        $sms = $outcome->forChannel(Channel::SMS);

        $this->assertNull($sms->rendered_subject);
        $this->assertStringNotContainsString('<p>', $sms->rendered_body);
    }

    // ------------------------------------------------------------ fixtures --

    private function suppress(string $channel, string $destination, string $reason): void
    {
        Suppression::create([
            'channel' => $channel,
            'destination' => Suppression::normalise($destination),
            'reason' => $reason,
        ]);
    }

    private function disablePreference(string $category, string $channel): string
    {
        $userId = (string) Str::uuid();

        NotificationPreference::create([
            'user_id' => $userId,
            'organization_id' => null,
            'category' => $category,
            'channel' => $channel,
            'enabled' => false,
        ]);

        return $userId;
    }

    private function sendReset(
        string $email,
        ?string $locale = null,
        ?string $userId = null,
        ?string $idempotencyKey = null,
    ): Notification {
        return $this->send->handle(SendRequest::toEmail(
            templateKey: 'password.reset',
            email: $email,
            variables: [
                'first_name' => 'Nathan',
                'reset_url' => 'https://app.sekuu.com/reset?token=abc',
                'expires_in_hours' => '1',
            ],
            userId: $userId,
            locale: $locale,
            idempotencyKey: $idempotencyKey,
        ))->first();
    }
}
