<?php

declare(strict_types=1);

namespace Modules\Billing\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Billing\Domain\Models\Invoice;
use Modules\Billing\Domain\Models\Subscription;
use Modules\Billing\Domain\SubscriptionStatus;
use Modules\Billing\Tests\Concerns\BillsAnOrganization;
use Modules\Payments\Application\Payments\ReconcilePayments;
use Modules\Payments\Domain\Models\PaymentIntent;
use Modules\Payments\Domain\Models\PaymentTransaction;
use Modules\Payments\Domain\Models\ProviderEvent;
use Modules\Payments\Infrastructure\Providers\ChargeOutcome;
use Modules\Payments\Infrastructure\Webhooks\WebhookRegistry;
use Modules\Payments\Tests\Support\FakeProvider;
use Modules\Payments\Tests\Support\FakeWebhookHandler;
use Tests\TestCase;

/**
 * Le callback accélère une confirmation ; il n'en est jamais la seule source.
 *
 * @see docs/03-services/payments/05-providers.md
 */
final class WebhookAndReconciliationTest extends TestCase
{
    use BillsAnOrganization;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useFakeProviders();

        config()->set('payments.webhooks.primary', FakeWebhookHandler::class);
        config()->set('payments.tranzak.auth_key', 'secret-partage');

        $this->app->forgetInstance(WebhookRegistry::class);

        $this->signInAsOwner();
    }

    public function test_an_invalid_signature_is_refused_but_still_recorded(): void
    {
        $this->postJson('/api/v1/billing/webhooks/primary', [
            'authKey' => 'mauvais-secret',
            'resourceId' => 'ref-1',
        ])
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'WEBHOOK_SIGNATURE_INVALID');

        // Jeter en silence priverait de toute trace en cas de tentative de
        // fraude.
        $this->assertDatabaseHas('provider_events', ['signature_valid' => false]);
    }

    /**
     * Le secret partagé ne doit pas être conservé avec le corps brut : il
     * servirait à forger un callback valide à quiconque lirait la table.
     */
    public function test_the_shared_secret_is_never_stored(): void
    {
        $this->postJson('/api/v1/billing/webhooks/primary', [
            'authKey' => 'mauvais-secret',
            'resourceId' => 'ref-1',
        ])->assertStatus(401);

        $this->assertArrayNotHasKey('authKey', ProviderEvent::query()->firstOrFail()->payload);
    }

    /**
     * Le statut est **relu chez l'agrégateur**, jamais pris dans le corps du
     * callback. C'est ce qui neutralise un rejeu modifié — indispensable quand
     * l'authentification passe par un secret dans le corps plutôt qu'une
     * signature.
     */
    public function test_the_callback_body_never_decides_the_outcome(): void
    {
        $intent = $this->promptedPayment();
        $attempt = $intent->attempts()->firstOrFail();

        // Le corps prétend un succès ; l'agrégateur dira le contraire.
        FakeProvider::willPoll('primary', ChargeOutcome::failed('PAYMENT_FAILED', 'Solde insuffisant', $attempt->provider_ref));

        $this->postJson('/api/v1/billing/webhooks/primary', [
            'authKey' => 'secret-partage',
            'eventType' => 'REQUEST.COMPLETED',
            'resourceId' => $attempt->provider_ref,
            'status' => 'SUCCESSFUL',
            'amount' => 999999,
        ])->assertOk();

        $this->assertSame(PaymentIntent::FAILED, $intent->fresh()->status);
        $this->assertSame(Invoice::OPEN, Invoice::query()->firstOrFail()->status);
    }

    public function test_a_replayed_callback_is_processed_once(): void
    {
        $intent = $this->promptedPayment();
        $attempt = $intent->attempts()->firstOrFail();

        FakeProvider::willPoll('primary', ChargeOutcome::succeeded($attempt->provider_ref, gross: $intent->amount));

        $payload = [
            'authKey' => 'secret-partage',
            'eventType' => 'REQUEST.COMPLETED',
            'resourceId' => $attempt->provider_ref,
        ];

        $this->postJson('/api/v1/billing/webhooks/primary', $payload)
            ->assertOk()
            ->assertJsonPath('data.processed', true);

        $this->postJson('/api/v1/billing/webhooks/primary', $payload)
            ->assertOk()
            ->assertJsonPath('data.reason', 'duplicate');

        // Un seul encaissement au registre, malgré deux callbacks.
        $this->assertSame(1, PaymentTransaction::query()->where('type', 'charge')->count());
    }

    public function test_an_unknown_reference_is_reported_not_silently_dropped(): void
    {
        $this->postJson('/api/v1/billing/webhooks/primary', [
            'authKey' => 'secret-partage',
            'resourceId' => 'reference-inconnue',
        ])
            ->assertOk()
            ->assertJsonPath('data.reason', 'unknown_reference');

        $this->assertNotNull(ProviderEvent::query()->firstOrFail()->error);
    }

    /**
     * Le sondage seul suffit à constater un paiement : c'est ce qui empêche un
     * callback perdu de laisser un client débité sans accès.
     */
    public function test_polling_alone_settles_a_payment(): void
    {
        $intent = $this->promptedPayment();
        $attempt = $intent->attempts()->firstOrFail();

        FakeProvider::willPoll('primary', ChargeOutcome::succeeded($attempt->provider_ref, gross: $intent->amount));

        $result = $this->app->make(ReconcilePayments::class)->handle();

        $this->assertSame(1, $result['settled']);
        $this->assertSame(PaymentIntent::SUCCEEDED, $intent->fresh()->status);
        $this->assertSame(SubscriptionStatus::Active, Subscription::query()->firstOrFail()->status);
    }

    /**
     * `expired` signifie **on ne sait pas**, ce qui n'est pas « cela a
     * échoué ». Ces intentions partent au rapprochement manuel, jamais à une
     * nouvelle tentative automatique.
     */
    public function test_an_intent_past_its_deadline_becomes_unresolved_not_failed(): void
    {
        $intent = $this->promptedPayment();

        $intent->forceFill(['expires_at' => now()->subMinute()])->save();

        $this->app->make(ReconcilePayments::class)->handle();

        $intent->refresh();

        $this->assertSame(PaymentIntent::EXPIRED, $intent->status);
        $this->assertNotSame(PaymentIntent::FAILED, $intent->status);
        $this->assertSame('PAYMENT_UNRESOLVED', $intent->failure_code);
    }

    private function promptedPayment(): PaymentIntent
    {
        FakeProvider::willReturn('primary', ChargeOutcome::prompted('ref-primary-1'));

        $invoice = $this->subscribe('business');

        return $this->payInvoice($invoice, '+237650000000')->fresh();
    }
}
