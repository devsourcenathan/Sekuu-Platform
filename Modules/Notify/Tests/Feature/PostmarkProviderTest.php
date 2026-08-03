<?php

declare(strict_types=1);

namespace Modules\Notify\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Modules\Notify\Application\Sending\SendNotification;
use Modules\Notify\Application\Sending\SendRequest;
use Modules\Notify\Domain\Models\Notification;
use Modules\Notify\Domain\Models\Suppression;
use Modules\Notify\Infrastructure\Providers\ProviderRegistry;
use Tests\TestCase;

/**
 * @see docs/03-services/notify/01-overview.md
 */
final class PostmarkProviderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'notify.email.postmark.server_token' => 'jeton-serveur',
            'notify.email.postmark.from' => 'no-reply@sekuu.com',
        ]);
    }

    /**
     * Un fournisseur sans identifiants ne doit pas être essayé : le tenter
     * polluerait le journal de livraison de tentatives vouées à l'échec, et
     * masquerait les vraies pannes.
     */
    public function test_an_unconfigured_provider_is_never_attempted(): void
    {
        config(['notify.email.postmark.server_token' => null]);

        $providers = array_map(
            fn ($p) => $p->name(),
            $this->app->make(ProviderRegistry::class)->forChannel('email'),
        );

        $this->assertSame(['laravel-mail'], $providers);
    }

    public function test_postmark_takes_precedence_once_configured(): void
    {
        $providers = array_map(
            fn ($p) => $p->name(),
            $this->app->make(ProviderRegistry::class)->forChannel('email'),
        );

        $this->assertSame(['postmark', 'laravel-mail'], $providers);
    }

    public function test_a_message_is_sent_and_its_provider_id_recorded(): void
    {
        Http::fake(['api.postmarkapp.com/*' => Http::response(['MessageID' => 'pm-abc-123'], 200)]);

        $notification = $this->sendReset();

        $delivery = $notification->deliveries()->firstOrFail();

        $this->assertSame('postmark', $delivery->provider);
        $this->assertSame('accepted', $delivery->status);

        // C'est cet identifiant qui permettra au webhook de rapprocher le
        // rebond de la notification.
        $this->assertSame('pm-abc-123', $delivery->provider_message_id);
    }

    public function test_the_request_carries_the_token_and_the_metadata(): void
    {
        Http::fake(['api.postmarkapp.com/*' => Http::response(['MessageID' => 'pm-1'], 200)]);

        $notification = $this->sendReset();

        Http::assertSent(function (Request $request) use ($notification): bool {
            return $request->hasHeader('X-Postmark-Server-Token', 'jeton-serveur')
                && $request['To'] === 'nathan@sekuu.com'
                && $request['Metadata']['notification_id'] === $notification->id;
        });
    }

    /**
     * Mélanger les envois de masse au flux transactionnel dégraderait la
     * réputation de ce dernier — celui dont dépendent les liens de
     * réinitialisation.
     */
    public function test_transactional_messages_use_the_transactional_stream(): void
    {
        Http::fake(['api.postmarkapp.com/*' => Http::response(['MessageID' => 'pm-1'], 200)]);

        $this->sendReset();

        Http::assertSent(fn (Request $request) => $request['MessageStream'] === 'outbound');
    }

    /**
     * Postmark a déjà constaté que cette adresse ne reçoit plus : aucun webhook
     * ne viendra, il faut l'inscrire immédiatement.
     */
    public function test_an_inactive_recipient_is_suppressed_immediately(): void
    {
        Http::fake([
            'api.postmarkapp.com/*' => Http::response([
                'ErrorCode' => 406,
                'Message' => 'You tried to send to a recipient that has been marked as inactive.',
            ], 422),
        ]);

        $notification = $this->sendReset();

        $this->assertDatabaseHas('suppressions', [
            'channel' => 'email',
            'destination' => 'nathan@sekuu.com',
            'reason' => Suppression::HARD_BOUNCE,
            'source' => 'postmark',
        ]);

        $this->assertSame(Notification::FAILED, $notification->fresh()->status);
    }

    public function test_an_invalid_address_is_suppressed_as_invalid(): void
    {
        Http::fake([
            'api.postmarkapp.com/*' => Http::response([
                'ErrorCode' => 300,
                'Message' => 'Invalid email address.',
            ], 422),
        ]);

        $this->sendReset();

        $this->assertDatabaseHas('suppressions', ['reason' => Suppression::INVALID]);
    }

    /**
     * Un rejet métier n'est pas rattrapable par un autre fournisseur : une
     * adresse morte le reste.
     */
    public function test_a_business_rejection_does_not_fall_back(): void
    {
        Http::fake([
            'api.postmarkapp.com/*' => Http::response(['ErrorCode' => 406, 'Message' => 'Inactive'], 422),
        ]);

        $notification = $this->sendReset();

        $this->assertSame(1, $notification->deliveries()->count());
        $this->assertSame('postmark', $notification->deliveries()->firstOrFail()->provider);
    }

    /**
     * Une panne du fournisseur, en revanche, justifie la bascule.
     */
    public function test_a_provider_outage_falls_back_to_the_next_provider(): void
    {
        Http::fake(['api.postmarkapp.com/*' => Http::response(['Message' => 'Service unavailable'], 503)]);

        $notification = $this->sendReset();

        $deliveries = $notification->deliveries()->orderBy('attempt')->get();

        $this->assertCount(2, $deliveries);
        $this->assertSame('postmark', $deliveries[0]->provider);
        $this->assertSame('failed', $deliveries[0]->status);
        $this->assertSame('laravel-mail', $deliveries[1]->provider);
        $this->assertSame('accepted', $deliveries[1]->status);

        // Le message est bien parti : la bascule a rempli son office.
        $this->assertSame(Notification::SENT, $notification->fresh()->status);
        $this->assertDatabaseCount('suppressions', 0);
    }

    public function test_a_network_failure_also_falls_back(): void
    {
        Http::fake(['api.postmarkapp.com/*' => fn () => throw new \RuntimeException('timeout')]);

        $notification = $this->sendReset();

        $this->assertSame(Notification::SENT, $notification->fresh()->status);
        $this->assertSame(2, $notification->deliveries()->count());
    }

    private function sendReset(): Notification
    {
        return $this->app->make(SendNotification::class)->handle(SendRequest::toEmail(
            templateKey: 'password.reset',
            email: 'nathan@sekuu.com',
            variables: [
                'first_name' => 'Nathan',
                'reset_url' => 'https://app.sekuu.com/reset?token=abc',
                'expires_in_hours' => '1',
            ],
        ))->first();
    }
}
