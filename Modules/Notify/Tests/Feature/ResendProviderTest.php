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
final class ResendProviderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'notify.email.resend.api_key' => 're_cle_de_test',
            'notify.email.resend.from' => 'Sekuu <no-reply@sekuu.com>',
        ]);
    }

    public function test_resend_takes_precedence_once_configured(): void
    {
        $providers = array_map(
            fn ($p) => $p->name(),
            $this->app->make(ProviderRegistry::class)->forChannel('email'),
        );

        $this->assertSame(['resend', 'laravel-mail'], $providers);
    }

    /**
     * Sans clé, Resend n'est pas essayé : le tenter polluerait le journal de
     * livraison de tentatives vouées à l'échec.
     */
    public function test_an_unconfigured_provider_is_never_attempted(): void
    {
        config(['notify.email.resend.api_key' => null]);

        $providers = array_map(
            fn ($p) => $p->name(),
            $this->app->make(ProviderRegistry::class)->forChannel('email'),
        );

        $this->assertSame(['laravel-mail'], $providers);
    }

    public function test_a_message_is_sent_and_its_provider_id_recorded(): void
    {
        Http::fake(['api.resend.com/*' => Http::response(['id' => 're-abc-123'], 200)]);

        $notification = $this->sendReset();

        $delivery = $notification->deliveries()->firstOrFail();

        $this->assertSame('resend', $delivery->provider);
        $this->assertSame('accepted', $delivery->status);

        // C'est cet identifiant qui permettra au webhook de rapprocher le
        // rebond de la notification.
        $this->assertSame('re-abc-123', $delivery->provider_message_id);
    }

    public function test_the_request_carries_the_key_the_sender_and_the_headers(): void
    {
        Http::fake(['api.resend.com/*' => Http::response(['id' => 're-1'], 200)]);

        $notification = $this->sendReset();

        Http::assertSent(function (Request $request) use ($notification): bool {
            return $request->hasHeader('Authorization', 'Bearer re_cle_de_test')
                && $request['from'] === 'Sekuu <no-reply@sekuu.com>'
                && $request['to'] === ['nathan@sekuu.com']
                && $request['headers']['X-Sekuu-Notification-Id'] === $notification->id;
        });
    }

    /**
     * Resend refuse les étiquettes non alphanumériques : une clé de template
     * contient un point.
     */
    public function test_template_tags_are_sanitised(): void
    {
        Http::fake(['api.resend.com/*' => Http::response(['id' => 're-1'], 200)]);

        $this->sendReset();

        Http::assertSent(fn (Request $request) => $request['tags'][0]['value'] === 'password_reset');
    }

    public function test_an_invalid_address_is_suppressed(): void
    {
        Http::fake([
            'api.resend.com/*' => Http::response([
                'name' => 'validation_error',
                'message' => 'Invalid `to` field. The email address is not valid.',
            ], 422),
        ]);

        $notification = $this->sendReset();

        $this->assertDatabaseHas('suppressions', [
            'channel' => 'email',
            'destination' => 'nathan@sekuu.com',
            'reason' => Suppression::INVALID,
            'source' => 'resend',
        ]);

        $this->assertSame(Notification::FAILED, $notification->fresh()->status);
    }

    /**
     * Une clé révoquée est un problème de configuration, pas de destinataire :
     * supprimer l'adresse pour autant condamnerait des comptes valides.
     */
    public function test_an_authentication_error_never_suppresses_the_address(): void
    {
        Http::fake([
            'api.resend.com/*' => Http::response([
                'name' => 'invalid_api_key',
                'message' => 'API key is invalid.',
            ], 401),
        ]);

        $this->sendReset();

        $this->assertDatabaseCount('suppressions', 0);
    }

    /**
     * Le quota est réessayable, mais pas rattrapable par un autre fournisseur
     * du même compte : le backoff de la file s'en charge.
     */
    public function test_a_quota_error_is_retryable(): void
    {
        Http::fake([
            'api.resend.com/*' => Http::response([
                'name' => 'rate_limit_exceeded',
                'message' => 'Too many requests.',
            ], 429),
        ]);

        $notification = $this->sendReset();

        $delivery = $notification->deliveries()->firstOrFail();

        $this->assertSame('failed', $delivery->status);
        $this->assertSame('QUOTA_EXCEEDED', $delivery->error_code);
        $this->assertDatabaseCount('suppressions', 0);
    }

    public function test_a_provider_outage_falls_back_to_the_next_provider(): void
    {
        Http::fake(['api.resend.com/*' => Http::response(['message' => 'Service unavailable'], 503)]);

        $notification = $this->sendReset();

        $deliveries = $notification->deliveries()->orderBy('attempt')->get();

        $this->assertCount(2, $deliveries);
        $this->assertSame('resend', $deliveries[0]->provider);
        $this->assertSame('failed', $deliveries[0]->status);
        $this->assertSame('laravel-mail', $deliveries[1]->provider);
        $this->assertSame('accepted', $deliveries[1]->status);

        // Le message est bien parti : la bascule a rempli son office.
        $this->assertSame(Notification::SENT, $notification->fresh()->status);
        $this->assertDatabaseCount('suppressions', 0);
    }

    public function test_a_network_failure_also_falls_back(): void
    {
        Http::fake(['api.resend.com/*' => fn () => throw new \RuntimeException('timeout')]);

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
