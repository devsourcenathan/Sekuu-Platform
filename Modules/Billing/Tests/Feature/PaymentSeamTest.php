<?php

declare(strict_types=1);

namespace Modules\Billing\Tests\Feature;

use App\Platform\Contracts\PayableRef;
use App\Platform\Contracts\PayerContext;
use App\Platform\Contracts\PaymentSettlement;
use App\Platform\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Billing\Application\Invoicing\InvoicePayable;
use Modules\Billing\Domain\Models\Invoice;
use Modules\Billing\Domain\Models\Subscription;
use Modules\Billing\Domain\SubscriptionStatus;
use Modules\Billing\Tests\Concerns\BillsAnOrganization;
use Modules\Payments\Application\Payments\ReconcilePayments;
use Modules\Payments\Domain\Models\PaymentIntent;
use Modules\Payments\Infrastructure\Providers\ChargeOutcome;
use Modules\Payments\Infrastructure\Webhooks\WebhookRegistry;
use Modules\Payments\Tests\Support\FakeProvider;
use Modules\Payments\Tests\Support\FakeWebhookHandler;
use Tests\TestCase;

/**
 * La couture entre Billing et la couche de paiement.
 *
 * La règle de bascule, les callbacks et le sondage s'éprouvent **chez
 * Payments**, sur un objet payable factice : ils ne dépendent pas de la
 * facturation, et un test qui les éprouverait à travers une facture laisserait
 * croire le contraire.
 *
 * Ce qui reste ici est ce qui n'a de sens que pour Billing : la facture est-elle
 * réglée, l'abonnement s'ouvre-t-il, et la route de paiement traduit-elle
 * correctement les issues en codes HTTP.
 */
final class PaymentSeamTest extends TestCase
{
    use BillsAnOrganization;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useFakeProviders();
        $this->signInAsOwner();
    }

    /**
     * Un encaissement règle la facture et ouvre la période.
     */
    public function test_a_settled_payment_pays_the_invoice_and_activates_the_subscription(): void
    {
        FakeProvider::willReturn('primary', ChargeOutcome::succeeded('ref-1', gross: 178875));

        $invoice = $this->subscribe('business');
        $this->payInvoice($invoice);

        $this->assertSame(Invoice::PAID, $invoice->fresh()->status);
        $this->assertSame(SubscriptionStatus::Active, Subscription::query()->firstOrFail()->status);
    }

    /**
     * La couture ajoute un point de rejeu que rien ne couvrait.
     *
     * Le rejeu d'un callback est bloqué en amont par l'unicité
     * `(provider, provider_event_id)`. Mais le propriétaire de l'objet est
     * appelé par une méthode ordinaire : rien n'empêche qu'elle le soit deux
     * fois, par un callback puis par le sondage.
     */
    public function test_settling_the_same_payment_twice_pays_the_invoice_once(): void
    {
        FakeProvider::willReturn('primary', ChargeOutcome::succeeded('ref-1', gross: 178875));

        $invoice = $this->subscribe('business');
        $this->payInvoice($invoice);

        $invoice->refresh();

        $this->assertSame($invoice->total, $invoice->amount_paid);

        // Second règlement du même paiement, appelé directement.
        $intent = PaymentIntent::query()->firstOrFail();

        $this->app->make(InvoicePayable::class)->settled(new PaymentSettlement(
            paymentIntentId: $intent->id,
            subject: new PayableRef(InvoicePayable::TYPE, $invoice->id),
            payer: PayerContext::organization($invoice->organization_id),
            amount: Money::of($invoice->total, $invoice->currency),
            provider: 'primary',
        ));

        // Le montant réglé n'a pas doublé.
        $this->assertSame($invoice->total, $invoice->fresh()->amount_paid);
        $this->assertSame(1, PaymentIntent::query()->count());
    }

    /**
     * Le sondage seul ouvre l'accès : c'est ce qui empêche un callback perdu de
     * laisser un client débité sans son logiciel.
     */
    public function test_polling_alone_opens_the_access(): void
    {
        FakeProvider::willReturn('primary', ChargeOutcome::prompted('ref-primary-1'));

        $invoice = $this->subscribe('business');
        $intent = $this->payInvoice($invoice)->fresh();
        $attempt = $intent->attempts()->firstOrFail();

        $this->assertSame(Invoice::OPEN, $invoice->fresh()->status);

        FakeProvider::willPoll('primary', ChargeOutcome::succeeded($attempt->provider_ref, gross: $intent->amount));

        $this->app->make(ReconcilePayments::class)->handle();

        $this->assertSame(Invoice::PAID, $invoice->fresh()->status);
        $this->assertSame(SubscriptionStatus::Active, Subscription::query()->firstOrFail()->status);
    }

    /**
     * Le corps d'un callback ne règle jamais une facture : le statut est relu
     * chez l'agrégateur, qui dira le contraire.
     */
    public function test_a_lying_callback_never_pays_the_invoice(): void
    {
        config()->set('payments.webhooks.primary', FakeWebhookHandler::class);
        config()->set('payments.tranzak.auth_key', 'secret-partage');

        $this->app->forgetInstance(WebhookRegistry::class);

        FakeProvider::willReturn('primary', ChargeOutcome::prompted('ref-primary-1'));

        $invoice = $this->subscribe('business');
        $intent = $this->payInvoice($invoice)->fresh();
        $attempt = $intent->attempts()->firstOrFail();

        FakeProvider::willPoll('primary', ChargeOutcome::failed('PAYMENT_FAILED', 'Solde insuffisant', $attempt->provider_ref));

        $this->postJson('/api/v1/payments/webhooks/primary', [
            'authKey' => 'secret-partage',
            'eventType' => 'REQUEST.COMPLETED',
            'resourceId' => $attempt->provider_ref,
            'status' => 'SUCCESSFUL',
            'amount' => 999999,
        ])->assertOk();

        $this->assertSame(Invoice::OPEN, $invoice->fresh()->status);
        $this->assertSame(PaymentIntent::FAILED, $intent->fresh()->status);
    }

    /**
     * Tous les agrégateurs refusent : la route rend `503`, et non une erreur
     * générique. Le client doit comprendre qu'il peut réessayer plus tard.
     */
    public function test_the_route_maps_a_total_refusal_to_503(): void
    {
        FakeProvider::willReturn('primary', ChargeOutcome::rejected('PROVIDER_AUTH_FAILED', 'Clé refusée'));
        FakeProvider::willReturn('secondary', ChargeOutcome::rejected('PROVIDER_AUTH_FAILED', 'Panne'));

        $invoice = $this->subscribe();

        $this->withToken($this->ownerToken)
            ->postJson('/api/v1/payments', [
                'invoice_id' => $invoice->id,
                'msisdn' => '+237650000000',
            ])
            ->assertStatus(503)
            ->assertJsonPath('error.code', 'PROVIDER_UNAVAILABLE');

        $this->assertSame(PaymentIntent::FAILED, PaymentIntent::query()->firstOrFail()->status);
    }

    /**
     * Le garde-fou contre le client impatient, vu depuis la route : trois clics
     * ne produisent pas trois invites, donc pas trois débits.
     */
    public function test_the_route_maps_a_second_payment_to_409(): void
    {
        FakeProvider::willReturn('primary', ChargeOutcome::prompted('ref-1'));

        $invoice = $this->subscribe();

        $this->withToken($this->ownerToken)
            ->postJson('/api/v1/payments', ['invoice_id' => $invoice->id, 'msisdn' => '+237650000000'])
            ->assertStatus(202);

        $this->flushHeaders();

        $this->withToken($this->ownerToken)
            ->postJson('/api/v1/payments', ['invoice_id' => $invoice->id, 'msisdn' => '+237650000000'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'PAYMENT_ALREADY_PENDING');

        $this->assertSame(1, PaymentIntent::query()->count());
    }
}
