<?php

declare(strict_types=1);

namespace Modules\Billing\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Billing\Application\Payments\InitiatePayment;
use Modules\Billing\Application\Subscriptions\AdvanceLifecycle;
use Modules\Billing\Domain\Models\Invoice;
use Modules\Billing\Domain\Models\Subscription;
use Modules\Billing\Domain\SubscriptionStatus;
use Modules\Billing\Infrastructure\Providers\ChargeOutcome;
use Modules\Billing\Tests\Concerns\BillsAnOrganization;
use Modules\Billing\Tests\Support\FakeProvider;
use Tests\TestCase;

/**
 * @see docs/04-decisions/adr-0007-mobile-money-prepaid-subscriptions.md
 */
final class SubscriptionLifecycleTest extends TestCase
{
    use BillsAnOrganization;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useFakeProviders();
        $this->signInAsOwner();
    }

    public function test_the_catalogue_is_public(): void
    {
        $response = $this->getJson('/api/v1/plans')->assertOk();

        $plan = collect($response->json('data'))->firstWhere('key', 'clinic-pro');

        $this->assertSame(45000, $plan['prices'][0]['amount']);

        // Le franc CFA n'a pas de centime. Un client qui appliquerait le
        // réflexe « /100 » afficherait 450 XAF au lieu de 45 000.
        $this->assertSame(0, $plan['prices'][0]['currency_exponent']);
        $this->assertSame('45 000 XAF', $plan['prices'][0]['formatted']);
    }

    /**
     * Souscrire ne donne pas l'accès. Ouvrir d'abord et facturer ensuite
     * reviendrait à accorder un crédit à un inconnu.
     */
    public function test_subscribing_without_a_trial_issues_an_invoice_and_grants_nothing(): void
    {
        $response = $this->withToken($this->ownerToken)
            ->postJson('/api/v1/subscription', ['plan_key' => 'business'])
            ->assertCreated();

        // `pending` et non `suspended` : rien n'a jamais été ouvert, donc rien
        // n'a été perdu. La nuance décide de ce qu'on écrit au client.
        $this->assertSame(SubscriptionStatus::Pending->value, $response->json('data.subscription.status'));
        $this->assertFalse($response->json('data.subscription.access_open'));
        $this->assertSame(Invoice::OPEN, $response->json('data.invoice.status'));

        $this->assertDatabaseCount('organization_products', 0);
    }

    /**
     * L'essai est le seul chemin qui ouvre l'accès sans paiement : c'est tout
     * son objet.
     */
    public function test_a_trial_opens_access_without_payment(): void
    {
        $response = $this->withToken($this->ownerToken)
            ->postJson('/api/v1/subscription', ['plan_key' => 'clinic-pro'])
            ->assertCreated();

        $this->assertSame(SubscriptionStatus::Trialing->value, $response->json('data.subscription.status'));
        $this->assertNull($response->json('data.invoice'));

        // Identity a appliqué les droits publiés par Billing.
        $this->assertDatabaseCount('organization_products', 2);
    }

    /**
     * Le parcours complet : facture → paiement → accès ouvert.
     */
    public function test_paying_an_invoice_opens_the_products(): void
    {
        FakeProvider::willReturn('primary', ChargeOutcome::succeeded('ref-1', gross: 178875, fee: 3000));

        $invoice = $this->subscribe('business');

        $this->app->make(InitiatePayment::class)->handle($invoice, '+237650000000');

        $subscription = Subscription::query()->firstOrFail();

        $this->assertSame(SubscriptionStatus::Active, $subscription->status);
        $this->assertSame(Invoice::PAID, $invoice->fresh()->status);

        // Quatre produits pour le plan Business.
        $this->assertDatabaseCount('organization_products', 4);
        $this->assertDatabaseHas('organization_products', [
            'status' => 'active',
            'source' => 'subscription',
            'subscription_id' => $subscription->id,
        ]);
    }

    /**
     * Le client a payé le brut ; la plateforme a reçu moins. Confondre les deux
     * laisserait la facture impayée à hauteur de la commission — et
     * l'abonnement jamais activé.
     */
    public function test_the_aggregator_fee_is_recorded_separately(): void
    {
        FakeProvider::willReturn('primary', ChargeOutcome::succeeded('ref-1', gross: 178875, fee: 3000));

        $invoice = $this->subscribe('business');

        $this->app->make(InitiatePayment::class)->handle($invoice, '+237650000000');

        $this->assertDatabaseHas('transactions', ['type' => 'charge', 'amount' => $invoice->total]);
        $this->assertDatabaseHas('transactions', ['type' => 'fee', 'amount' => -3000]);

        // La facture est réglée sur le brut, pas sur le net.
        $this->assertSame(Invoice::PAID, $invoice->fresh()->status);
    }

    /**
     * La TVA est figée à l'émission : une facture passée continue d'afficher ce
     * qui a été réellement facturé, même si le taux change.
     */
    public function test_the_tax_rate_is_frozen_on_the_invoice(): void
    {
        $invoice = $this->subscribe('business');

        $this->assertSame(0.1925, $invoice->tax_rate);
        $this->assertSame(150000, $invoice->subtotal);
        $this->assertSame(28875, $invoice->tax_amount);
        $this->assertSame(178875, $invoice->total);

        config()->set('billing.tax.CM', 0.20);

        $this->assertSame(0.1925, $invoice->fresh()->tax_rate);
    }

    /**
     * Sans grâce, une clinique découvre un lundi matin qu'elle ne peut plus
     * ouvrir son agenda.
     */
    public function test_an_expired_period_enters_grace_with_access_still_open(): void
    {
        $this->subscribe('business');

        $subscription = Subscription::query()->firstOrFail();
        $this->expirePeriod($subscription);

        $this->app->make(AdvanceLifecycle::class)->handle();

        $subscription->refresh();

        $this->assertSame(SubscriptionStatus::Grace, $subscription->status);
        $this->assertTrue($subscription->grantsAccess());
    }

    /**
     * Idempotence : relancer la commande deux fois le même jour ne raccourcit
     * pas la grâce. C'est la raison d'être d'une date absolue.
     */
    public function test_advancing_twice_does_not_shorten_the_grace(): void
    {
        $this->subscribe('business');

        $subscription = Subscription::query()->firstOrFail();
        $this->expirePeriod($subscription);

        $advance = $this->app->make(AdvanceLifecycle::class);
        $advance->handle();

        $first = $subscription->fresh()->grace_ends_at;

        $advance->handle();

        $this->assertTrue($first->equalTo($subscription->fresh()->grace_ends_at));
    }

    /**
     * Fermer l'accès ne détruit rien : les données appartiennent au client, pas
     * au contrat.
     */
    public function test_grace_running_out_suspends_access_without_deleting_anything(): void
    {
        FakeProvider::willReturn('primary', ChargeOutcome::succeeded('ref-1', gross: 178875));

        $invoice = $this->subscribe('business');
        $this->app->make(InitiatePayment::class)->handle($invoice, '+237650000000');

        Subscription::query()->firstOrFail()->forceFill([
            'status' => SubscriptionStatus::Grace,
            'grace_ends_at' => now()->subHour(),
        ])->save();

        $this->app->make(AdvanceLifecycle::class)->handle();

        $this->assertSame(SubscriptionStatus::Suspended, Subscription::query()->firstOrFail()->status);

        // Les droits sont suspendus, jamais supprimés.
        $this->assertDatabaseCount('organization_products', 4);
        $this->assertSame(4, DB::table('organization_products')->where('status', 'suspended')->count());
    }

    /**
     * Résilier ne coupe pas l'accès : la période est payée. Couper
     * immédiatement obligerait à rembourser.
     */
    public function test_cancelling_keeps_access_until_the_end_of_the_period(): void
    {
        $this->subscribe('clinic-pro');

        $response = $this->withToken($this->ownerToken)
            ->postJson('/api/v1/subscription/cancel', ['reason' => 'trop cher'])
            ->assertOk();

        $this->assertTrue($response->json('data.cancel_at_period_end'));
        $this->assertTrue($response->json('data.access_open'));
    }

    public function test_a_second_subscription_is_refused(): void
    {
        $this->subscribe('clinic-pro');

        $this->withToken($this->ownerToken)
            ->postJson('/api/v1/subscription', ['plan_key' => 'business'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'SUBSCRIPTION_ALREADY_ACTIVE');
    }
}
