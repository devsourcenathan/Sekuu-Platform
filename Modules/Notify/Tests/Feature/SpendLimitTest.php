<?php

declare(strict_types=1);

namespace Modules\Notify\Tests\Feature;

use App\Platform\Exceptions\DomainException;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Modules\Notify\Application\Sending\SendNotification;
use Modules\Notify\Application\Sending\SendOutcome;
use Modules\Notify\Application\Sending\SendRequest;
use Modules\Notify\Domain\Channel;
use Modules\Notify\Domain\Models\Notification;
use Modules\Notify\Domain\Models\NotificationDelivery;
use Modules\Notify\Domain\Models\NotificationTemplate;
use Modules\Notify\Infrastructure\Console\PurgeNotificationsCommand;
use Tests\TestCase;

/**
 * @see docs/03-services/notify/02-data-model.md
 */
final class SpendLimitTest extends TestCase
{
    use RefreshDatabase;

    private string $organizationId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organizationId = (string) Str::uuid();

        config([
            'notify.limits.currency' => 'XAF',
            'notify.limits.sms.monthly_cost' => 100,
            'notify.sms.local_gateway.endpoint' => 'https://gateway.test/send',
            'notify.sms.local_gateway.token' => 'secret',
        ]);

        Http::fake(['*' => Http::response(['message_id' => 'sms-1', 'cost' => 25, 'currency' => 'XAF'], 200)]);
    }

    /**
     * Une commande de purge que rien n'exécute laisse la table croître
     * indéfiniment, sans que personne ne s'en aperçoive.
     */
    public function test_the_purge_is_scheduled(): void
    {
        $events = collect($this->app->make(Schedule::class)->events())
            ->filter(fn ($event) => str_contains($event->command ?? '', 'notify:purge'));

        $this->assertCount(1, $events, 'La purge doit être planifiée.');

        // Une purge concurrente sur deux serveurs dédoublerait les agrégats.
        $this->assertSame('15 3 * * *', $events->first()->expression);
    }

    public function test_the_purge_command_is_registered(): void
    {
        $this->assertArrayHasKey('notify:purge', $this->app->make(Kernel::class)->all());
        $this->assertTrue(class_exists(PurgeNotificationsCommand::class));
    }

    // ----------------------------------------------------- plafond de dépense --

    public function test_a_send_passes_below_the_limit(): void
    {
        $this->recordSpend(50);

        $outcome = $this->sendSms();

        $this->assertTrue($outcome->sentAnything());
    }

    /**
     * Sans ce garde-fou, une boucle dans un produit ou une clé fuitée se
     * traduit par une facture que rien n'arrête avant le relevé.
     */
    public function test_a_send_is_refused_once_the_limit_is_reached(): void
    {
        $this->recordSpend(100);

        try {
            $this->sendSms();
            $this->fail('Le plafond devait bloquer cet envoi.');
        } catch (DomainException $e) {
            $this->assertSame('QUOTA_EXCEEDED', $e->errorCode);
            $this->assertSame(429, $e->status);
        }
    }

    public function test_another_organisation_is_unaffected(): void
    {
        $this->recordSpend(200);

        // Le plafond est calculé par organisation : la dépense de l'une ne
        // bloque pas l'autre.
        $outcome = $this->sendSms(organizationId: (string) Str::uuid());

        $this->assertTrue($outcome->sentAnything());
    }

    public function test_spending_from_a_previous_month_does_not_count(): void
    {
        $this->recordSpend(500, now()->subMonth()->startOfMonth());

        $outcome = $this->sendSms();

        $this->assertTrue($outcome->sentAnything());
    }

    /**
     * Additionner des devises différentes n'aurait aucun sens.
     */
    public function test_a_different_currency_is_not_counted(): void
    {
        $this->recordSpend(500, currency: 'EUR');

        $outcome = $this->sendSms();

        $this->assertTrue($outcome->sentAnything());
    }

    public function test_no_limit_means_no_control(): void
    {
        config(['notify.limits.sms.monthly_cost' => null]);
        $this->recordSpend(10_000);

        $this->assertTrue($this->sendSms()->sentAnything());
    }

    /**
     * Le plafond ne s'applique qu'aux canaux facturés : bloquer un email
     * transactionnel pour une histoire de coût SMS serait absurde.
     */
    public function test_the_email_channel_is_not_capped(): void
    {
        $this->recordSpend(10_000);

        $outcome = $this->app->make(SendNotification::class)->handle(SendRequest::toEmail(
            templateKey: 'password.reset',
            email: 'nathan@sekuu.com',
            variables: ['first_name' => 'Nathan', 'reset_url' => 'https://app.sekuu.com/r', 'expires_in_hours' => '1'],
            organizationId: $this->organizationId,
        ));

        $this->assertTrue($outcome->sentAnything());
    }

    // ------------------------------------------------------------- lecture --

    public function test_the_usage_endpoint_reports_the_spending(): void
    {
        $this->recordSpend(40);

        [$token, $organizationId] = $this->signInWithOrganisation();
        $this->recordSpend(30, organizationId: $organizationId);

        $response = $this->withToken($token)->getJson('/api/v1/notifications/usage')->assertOk();

        // `round()` renvoie un entier lorsque la décimale est nulle : on
        // compare les valeurs, pas leur type de sérialisation.
        $this->assertEqualsWithDelta(30, $response->json('data.channels.sms.cost.spent'), 0.001);
        $this->assertEqualsWithDelta(100, $response->json('data.channels.sms.cost.limit'), 0.001);
        $this->assertEqualsWithDelta(70, $response->json('data.channels.sms.cost.remaining'), 0.001);
        $this->assertSame('XAF', $response->json('data.channels.sms.cost.currency'));
    }

    /**
     * `null` signale une absence de contrôle, pas un budget infini mesuré.
     */
    public function test_an_unlimited_channel_reports_no_remaining_budget(): void
    {
        config(['notify.limits.sms.monthly_cost' => null]);

        [$token] = $this->signInWithOrganisation();

        $response = $this->withToken($token)->getJson('/api/v1/notifications/usage')->assertOk();

        $this->assertNull($response->json('data.channels.sms.cost.limit'));
        $this->assertNull($response->json('data.channels.sms.cost.remaining'));
    }

    public function test_the_usage_endpoint_requires_an_active_organisation(): void
    {
        $token = $this->postJson('/api/v1/auth/register', [
            'first_name' => 'Nathan',
            'last_name' => 'Tchinda',
            'email' => 'sans-org@sekuu.com',
            'password' => 'un-mot-de-passe-long',
        ])->assertCreated()->json('data.access_token');

        $this->withToken($token)->getJson('/api/v1/notifications/usage')
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'ORGANIZATION_REQUIRED');
    }

    // ------------------------------------------------------------ fixtures --

    private function sendSms(?string $organizationId = null): SendOutcome
    {
        return $this->app->make(SendNotification::class)->handle(new SendRequest(
            templateKey: 'password.changed',
            recipients: [Channel::SMS => '+237690000000'],
            variables: ['first_name' => 'Nathan', 'changed_at' => '2026-08-03'],
            organizationId: $organizationId ?? $this->organizationId,
        ));
    }

    private function recordSpend(
        float $amount,
        ?\DateTimeInterface $at = null,
        string $currency = 'XAF',
        ?string $organizationId = null,
    ): void {
        $template = NotificationTemplate::query()
            ->where('key', 'password.changed')->where('channel', 'sms')->firstOrFail();

        $notification = Notification::create([
            'organization_id' => $organizationId ?? $this->organizationId,
            'template_id' => $template->id,
            'template_key' => $template->key,
            'channel' => Channel::SMS,
            'category' => 'transactional',
            'locale' => 'fr',
            'recipient' => '+237690000000',
            'rendered_body' => 'corps',
            'status' => Notification::SENT,
        ]);

        $delivery = NotificationDelivery::create([
            'notification_id' => $notification->id,
            'provider' => 'local-gateway',
            'attempt' => 1,
            'status' => NotificationDelivery::ACCEPTED,
            'cost_amount' => $amount,
            'cost_currency' => $currency,
            'sent_at' => now(),
        ]);

        if ($at !== null) {
            $delivery->forceFill(['created_at' => $at])->save();
        }
    }

    /**
     * @return array{string, string}
     */
    private function signInWithOrganisation(): array
    {
        $token = $this->postJson('/api/v1/auth/register', [
            'first_name' => 'Nathan',
            'last_name' => 'Tchinda',
            'email' => 'nathan@sekuu.com',
            'password' => 'un-mot-de-passe-long',
        ])->assertCreated()->json('data.access_token');

        $organizationId = $this->withToken($token)
            ->postJson('/api/v1/organizations', ['name' => 'SOS Clinique'])
            ->assertCreated()->json('data.id');

        $contextToken = $this->withToken($token)
            ->postJson('/api/v1/auth/switch-organization', ['organization_id' => $organizationId])
            ->assertOk()->json('data.access_token');

        return [$contextToken, $organizationId];
    }
}
