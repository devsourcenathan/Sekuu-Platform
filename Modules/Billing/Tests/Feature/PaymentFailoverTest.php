<?php

declare(strict_types=1);

namespace Modules\Billing\Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Billing\Tests\Concerns\BillsAnOrganization;
use Modules\Payments\Application\Payments\SettlePayment;
use Modules\Payments\Domain\AttemptStatus;
use Modules\Payments\Domain\Models\PaymentIntent;
use Modules\Payments\Infrastructure\Providers\ChargeOutcome;
use Modules\Payments\Tests\Support\FakeProvider;
use Tests\TestCase;

/**
 * La règle la plus importante du module.
 *
 * Chaque test ici protège contre un **double débit** — une faute que le client
 * découvre sur son relevé, et qu'un remboursement Mobile Money rend pénible à
 * corriger.
 *
 * @see docs/04-decisions/adr-0008-payment-aggregators-failover.md
 */
final class PaymentFailoverTest extends TestCase
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
     * Le seul cas qui autorise une bascule : l'agrégateur a refusé la demande,
     * donc le client n'a jamais rien reçu.
     */
    public function test_a_rejected_request_falls_over_to_the_next_aggregator(): void
    {
        FakeProvider::willReturn('primary', ChargeOutcome::rejected('PROVIDER_AUTH_FAILED', 'Clé refusée'));
        FakeProvider::willReturn('secondary', ChargeOutcome::prompted('ref-secondary-1'));

        $intent = $this->pay();

        $this->assertSame(['primary', 'secondary'], FakeProvider::$charged);
        $this->assertSame(PaymentIntent::PENDING, $intent->status);

        $attempts = $intent->attempts()->orderBy('priority')->get();

        $this->assertSame(AttemptStatus::Rejected, $attempts[0]->status);
        $this->assertFalse($attempts[0]->customer_prompted);
        $this->assertSame(AttemptStatus::Prompted, $attempts[1]->status);
    }

    /**
     * Le cœur du sujet : une fois l'invite partie, on n'essaie plus personne.
     * Le client peut la valider avec dix minutes de retard.
     */
    public function test_a_prompted_customer_stops_the_failover(): void
    {
        FakeProvider::willReturn('primary', ChargeOutcome::prompted('ref-primary-1'));

        $this->pay();

        $this->assertSame(['primary'], FakeProvider::$charged);
    }

    /**
     * Un solde insuffisant chez MTN le reste quel que soit l'agrégateur qui
     * pose la question. C'est la règle déjà posée pour Notify — un rejet métier
     * ne réussira pas davantage ailleurs — avec ici un enjeu supérieur.
     */
    public function test_a_business_failure_does_not_fall_over(): void
    {
        FakeProvider::willReturn('primary', ChargeOutcome::failed('PAYMENT_FAILED', 'Solde insuffisant'));

        $intent = $this->pay();

        $this->assertSame(['primary'], FakeProvider::$charged);
        $this->assertSame(PaymentIntent::FAILED, $intent->status);
    }

    /**
     * Le cas le plus banal et le plus dangereux : l'appel expire, on ignore si
     * la demande a atteint l'agrégateur. Réessayer ailleurs double-débiterait.
     */
    public function test_an_unknown_outcome_never_falls_over(): void
    {
        FakeProvider::willReturn('primary', ChargeOutcome::unknown('Temporisation réseau'));

        $intent = $this->pay();

        $this->assertSame(['primary'], FakeProvider::$charged);

        // L'incertitude compte comme « invite partie ».
        $this->assertTrue($intent->attempts()->first()->customer_prompted);
        $this->assertSame(PaymentIntent::PROCESSING, $intent->status);
    }

    public function test_every_aggregator_rejecting_charges_nobody(): void
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

        $this->assertSame(['primary', 'secondary'], FakeProvider::$charged);
        $this->assertSame(PaymentIntent::FAILED, PaymentIntent::query()->firstOrFail()->status);
    }

    /**
     * Le garde-fou contre le client impatient : trois clics ne produisent pas
     * trois invites, donc pas trois débits.
     */
    public function test_a_second_payment_on_the_same_invoice_is_refused(): void
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

    /**
     * Le jumeau du test précédent, **sans facture**.
     *
     * L'index d'unicité portait auparavant sur `invoice_id` et excluait
     * explicitement les intentions qui n'en avaient pas : un paiement sans
     * facture n'avait donc aucune protection anti-triple-clic. Sans conséquence
     * tant que seul un abonnement se payait — et c'est exactement le cas
     * nominal d'un produit qui vend autre chose.
     */
    public function test_a_second_payment_on_any_subject_is_refused(): void
    {
        $intent = PaymentIntent::create([
            'subject_type' => 'learn.enrollment',
            'subject_id' => (string) Str::uuid(),
            'payer_type' => PaymentIntent::PAYER_USER,
            'payer_id' => (string) Str::uuid(),
            'amount' => 15000,
            'currency' => 'XAF',
            'method' => 'mobile_money',
            'status' => PaymentIntent::PENDING,
            'expires_at' => now()->addMinutes(10),
        ]);

        $this->expectException(QueryException::class);

        // Même sujet, aucune facture : la base doit refuser.
        PaymentIntent::create([
            'subject_type' => $intent->subject_type,
            'subject_id' => $intent->subject_id,
            'payer_type' => PaymentIntent::PAYER_USER,
            'payer_id' => (string) Str::uuid(),
            'amount' => 15000,
            'currency' => 'XAF',
            'method' => 'mobile_money',
            'status' => PaymentIntent::PENDING,
            'expires_at' => now()->addMinutes(10),
        ]);
    }

    /**
     * L'idempotence est scopée au payeur.
     *
     * Elle était globale, et la recherche correspondante ne filtrait sur rien :
     * deux produits dont les clients dérivent leurs clés du métier auraient pu
     * se renvoyer mutuellement leurs intentions.
     */
    public function test_the_same_idempotency_key_may_serve_two_payers(): void
    {
        foreach ([Str::uuid(), Str::uuid()] as $payeur) {
            PaymentIntent::create([
                'subject_type' => 'learn.enrollment',
                'subject_id' => (string) Str::uuid(),
                'payer_type' => PaymentIntent::PAYER_USER,
                'payer_id' => (string) $payeur,
                'amount' => 15000,
                'currency' => 'XAF',
                'method' => 'mobile_money',
                'status' => PaymentIntent::PENDING,
                'idempotency_key' => 'order-1',
                'expires_at' => now()->addMinutes(10),
            ]);
        }

        $this->assertSame(2, PaymentIntent::query()->where('idempotency_key', 'order-1')->count());
    }

    /**
     * Une invite partie ne se rétracte pas : écraser ce drapeau par un `false`
     * venu d'un statut mal traduit rouvrirait la porte au double débit.
     */
    public function test_the_prompted_flag_is_never_downgraded(): void
    {
        FakeProvider::willReturn('primary', ChargeOutcome::prompted('ref-1'));

        $intent = $this->pay();
        $attempt = $intent->attempts()->firstOrFail();

        $this->app->make(SettlePayment::class)
            ->applyToAttempt($attempt, ChargeOutcome::rejected('X', 'y'));

        $this->assertTrue($attempt->fresh()->customer_prompted);
        $this->assertFalse($attempt->fresh()->allowsFailover());
    }

    private function pay(): PaymentIntent
    {
        $invoice = $this->subscribe();

        return $this->payInvoice($invoice, '+237650000000')->fresh();
    }
}
