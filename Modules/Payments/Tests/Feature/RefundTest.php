<?php

declare(strict_types=1);

namespace Modules\Payments\Tests\Feature;

use App\Platform\Exceptions\DomainException;
use App\Platform\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Modules\Identity\Domain\Models\ApiKey;
use Modules\Payments\Application\External\ExternalPayable;
use Modules\Payments\Application\Payments\PayableRegistry;
use Modules\Payments\Application\Refunds\RequestRefund;
use Modules\Payments\Application\Refunds\SettleRefund;
use Modules\Payments\Domain\Models\ExternalCharge;
use Modules\Payments\Domain\Models\PaymentDelivery;
use Modules\Payments\Domain\Models\PaymentEndpoint;
use Modules\Payments\Domain\Models\PaymentIntent;
use Modules\Payments\Domain\Models\PaymentTransaction;
use Modules\Payments\Domain\Models\Refund;
use Modules\Payments\Infrastructure\Providers\ChargeOutcome;
use Modules\Payments\Tests\Concerns\PaysAFakeSubject;
use Modules\Payments\Tests\Support\FakePayable;
use Modules\Payments\Tests\Support\FakeProvider;
use Tests\Concerns\SignsInAsOwner;
use Tests\TestCase;

/**
 * Rendre l'argent.
 *
 * Deux invariants, et ils ne se ressemblent pas :
 *
 *  - **on ne rend jamais plus qu'on n'a encaissé**, garde par la couche de
 *    paiement, sans qu'aucun produit puisse s'en affranchir ;
 *  - **on ne rend pas deux fois**, qui est le miroir du double débit — sauf que
 *    le client n'a aucune raison de signaler l'erreur.
 *
 * @see docs/03-services/payments/08-refunds.md
 */
final class RefundTest extends TestCase
{
    use PaysAFakeSubject;
    use RefreshDatabase;
    use SignsInAsOwner;

    private const TYPE = 'learn.enrollment';

    private string $plainKey;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useFakePayments();
        $this->registerExternalPayable();
        $this->signInAsOwner();

        $this->plainKey = $this->issueKey();
    }

    /**
     * Le parcours complet : décider, décaisser, constater.
     */
    public function test_a_refund_is_decided_then_settled_by_hand(): void
    {
        $charge = $this->paidCharge();

        $this->refund($charge, ['reason' => 'Formation annulee'])
            ->assertStatus(202)
            ->assertJsonPath('data.status', Refund::PENDING)
            ->assertJsonPath('data.amount', 15000);

        $refund = Refund::query()->firstOrFail();

        // **Rien n'est sorti.** La decision n'ecrit pas au registre : l'argent
        // est encore sur le compte marchand.
        $this->assertSame(0, PaymentTransaction::query()->where('type', 'refund')->count());

        $this->app->make(SettleRefund::class)->succeeded($refund, provider: null, providerRef: 'TRF-001');

        $this->assertSame(Refund::SUCCEEDED, $refund->fresh()->status);

        // La ligne de registre, negative, ecrite au decaissement constate.
        $this->assertDatabaseHas('payment_transactions', [
            'type' => 'refund',
            'amount' => -15000,
        ]);
    }

    /**
     * **L'invariant de la couche de paiement.** Aucun produit n'a à en décider.
     */
    public function test_a_refund_never_exceeds_what_was_collected(): void
    {
        $charge = $this->paidCharge();

        $this->refund($charge, ['amount' => 15001, 'reason' => 'Trop'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'REFUND_EXCEEDS_PAYMENT');

        $this->assertDatabaseCount('refunds', 0);
    }

    /**
     * Le plafond porte sur le **cumul**, pas sur chaque demande prise isolément.
     */
    public function test_partial_refunds_cannot_add_up_beyond_the_payment(): void
    {
        $charge = $this->paidCharge();

        $this->refund($charge, ['amount' => 10000, 'reason' => 'Partiel'])->assertStatus(202);
        $this->refund($charge, ['amount' => 5000, 'reason' => 'Le reste'])->assertStatus(202);

        $this->refund($charge, ['amount' => 1, 'reason' => 'Un franc de trop'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'REFUND_EXCEEDS_PAYMENT');

        $this->assertSame(2, Refund::query()->count());
    }

    /**
     * Un remboursement **en attente** immobilise déjà la somme.
     *
     * Ne compter que les décaissements constatés laisserait décider deux fois le
     * même remboursement avant que le premier ne soit versé — et les deux
     * partiraient.
     */
    public function test_a_pending_refund_already_holds_the_funds(): void
    {
        $charge = $this->paidCharge();

        $this->refund($charge, ['amount' => 15000, 'reason' => 'Tout'])->assertStatus(202);

        $this->assertSame(Refund::PENDING, Refund::query()->firstOrFail()->status);

        $this->refund($charge, ['amount' => 1, 'reason' => 'Encore'])
            ->assertStatus(422);
    }

    /**
     * Un décaissement échoué rend la somme à nouveau disponible : rien n'est
     * sorti.
     */
    public function test_a_failed_disbursement_releases_the_funds(): void
    {
        $charge = $this->paidCharge();

        $this->refund($charge, ['amount' => 15000, 'reason' => 'Tout'])->assertStatus(202);

        $refund = Refund::query()->firstOrFail();

        $this->app->make(SettleRefund::class)->failed($refund, 'REFUND_TRANSFER_FAILED', 'Solde marchand insuffisant');

        // Aucune ligne de registre : l'argent n'a pas bouge.
        $this->assertSame(0, PaymentTransaction::query()->where('type', 'refund')->count());

        // Et la somme redevient remboursable.
        $this->refund($charge, ['amount' => 15000, 'reason' => 'Nouvelle tentative'])
            ->assertStatus(202);
    }

    /**
     * **Le miroir du double débit.** Constater deux fois n'écrit qu'une ligne.
     */
    public function test_settling_the_same_refund_twice_moves_money_once(): void
    {
        $charge = $this->paidCharge();
        $this->refund($charge, ['reason' => 'Annulation'])->assertStatus(202);

        $refund = Refund::query()->firstOrFail();
        $settle = $this->app->make(SettleRefund::class);

        $settle->succeeded($refund, providerRef: 'TRF-001');
        $settle->succeeded($refund, providerRef: 'TRF-002');

        $this->assertSame(1, PaymentTransaction::query()->where('type', 'refund')->count());
        $this->assertSame('TRF-001', $refund->fresh()->provider_ref);
    }

    /**
     * L'idempotence protège du rejeu réseau, comme à l'encaissement.
     */
    public function test_the_same_idempotency_key_never_refunds_twice(): void
    {
        $charge = $this->paidCharge();
        $key = (string) Str::uuid();

        $first = $this->refund($charge, ['amount' => 5000, 'reason' => 'Geste'], $key)
            ->assertStatus(202)->json('data.refund_id');

        $second = $this->refund($charge, ['amount' => 5000, 'reason' => 'Geste'], $key)
            ->assertStatus(202)->json('data.refund_id');

        $this->assertSame($first, $second);
        $this->assertSame(1, Refund::query()->count());
    }

    /**
     * Sans montant, on rend **tout ce qui reste**.
     *
     * Obliger le produit à calculer le reliquat lui ferait tenir une seconde
     * comptabilité, qui finirait par diverger de celle de la plateforme.
     */
    public function test_omitting_the_amount_refunds_what_remains(): void
    {
        $charge = $this->paidCharge();

        $this->refund($charge, ['amount' => 4000, 'reason' => 'Partiel'])->assertStatus(202);

        $this->refund($charge, ['reason' => 'Le reste'])
            ->assertStatus(202)
            ->assertJsonPath('data.amount', 11000);
    }

    /**
     * **Billing ne rembourse pas**, et ce n'est pas une lacune.
     *
     * Un trop-perçu y devient un crédit imputé au prochain paiement — décision
     * de l'ADR-0007. Ne pas porter `RefundableSource` est la façon dont ce refus
     * s'exprime, et il échoue durement plutôt que silencieusement.
     */
    public function test_an_owner_that_does_not_refund_fails_loudly(): void
    {
        $charge = $this->paidCharge();
        $intent = PaymentIntent::query()->firstOrFail();

        // Le type reste servi, mais par un propriétaire qui ne rembourse pas.
        config()->set('payments.payables', [self::TYPE => FakePayable::class]);
        $this->app->forgetInstance(PayableRegistry::class);

        try {
            $this->app->make(RequestRefund::class)->handle(
                intent: $intent,
                amount: Money::of(1000, 'XAF'),
                reason: 'Peu importe',
            );
            $this->fail('Un proprietaire qui ne rembourse pas doit lever.');
        } catch (DomainException $e) {
            $this->assertSame('REFUND_NOT_SUPPORTED', $e->errorCode);
        }
    }

    /**
     * On ne rembourse pas un paiement qui n'a jamais abouti.
     */
    public function test_an_unsettled_payment_cannot_be_refunded(): void
    {
        FakeProvider::willReturn('primary', ChargeOutcome::prompted('ref-1'));

        $this->charge()->assertStatus(202);

        $charge = ExternalCharge::query()->firstOrFail();
        $intent = PaymentIntent::query()->firstOrFail();

        try {
            $this->app->make(RequestRefund::class)->handle(
                intent: $intent,
                amount: Money::of(1000, 'XAF'),
                reason: 'Trop tot',
            );
            $this->fail('Un paiement non abouti ne se rembourse pas.');
        } catch (DomainException $e) {
            // Et non `CHARGE_NOT_FOUND` : la charge existe, elle n'est pas payee.
            $this->assertSame('PAYMENT_NOT_SETTLED', $e->errorCode);
        }
    }

    /**
     * Encaisser et rembourser sont deux droits distincts : ce sont deux dangers
     * opposés, et un seul scope pour les deux serait le plus large des deux.
     */
    public function test_refunding_requires_its_own_scope(): void
    {
        $charge = $this->paidCharge();

        $withoutRefund = $this->issueKey(['payments.charge', 'payments.read']);

        $this->flushHeaders();
        $this->withToken($withoutRefund)
            ->postJson("/api/v1/payments/charges/{$charge->id}/refunds", ['reason' => 'Tentative'])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'INSUFFICIENT_PERMISSIONS');
    }

    /**
     * La charge d'un autre produit reste invisible : `404`, jamais `403`.
     */
    public function test_a_charge_of_another_product_is_not_refundable(): void
    {
        $other = ExternalCharge::create([
            'organization_id' => (string) Str::uuid(),
            'subject_type' => self::TYPE,
            'subject_id' => (string) Str::uuid(),
            'payer_type' => 'learn.learner',
            'payer_id' => (string) Str::uuid(),
            'amount' => 9000,
            'currency' => 'XAF',
            'description' => 'Ailleurs',
            'status' => ExternalCharge::PAID,
        ]);

        $this->refund($other, ['reason' => 'Tentative'])->assertStatus(404);
    }

    /**
     * Le produit est prévenu du décaissement, avec le montant **rendu** — qui
     * n'est pas celui de la charge sur un remboursement partiel.
     */
    public function test_the_product_is_told_what_was_actually_returned(): void
    {
        Bus::fake();

        $charge = $this->paidCharge();
        $this->endpoint();

        $this->refund($charge, ['amount' => 4000, 'reason' => 'Partiel'])->assertStatus(202);

        $this->app->make(SettleRefund::class)
            ->succeeded(Refund::query()->firstOrFail(), providerRef: 'TRF-001');

        $delivery = PaymentDelivery::query()
            ->where('event_type', 'refund.succeeded')
            ->firstOrFail();

        $this->assertSame(4000, $delivery->payload['data']['amount']);
        $this->assertSame(15000, $delivery->payload['data']['charge_amount']);
    }

    // ------------------------------------------------------------------ outils

    private function paidCharge(): ExternalCharge
    {
        FakeProvider::willReturn('primary', ChargeOutcome::succeeded('ref-1', gross: 15000, fee: 450));

        $this->charge()->assertStatus(202);

        return ExternalCharge::query()->firstOrFail();
    }

    private function charge(): TestResponse
    {
        $this->flushHeaders();

        return $this->withToken($this->plainKey)->postJson('/api/v1/payments/charges', [
            'subject_type' => self::TYPE,
            'subject_id' => (string) Str::uuid(),
            'payer_type' => 'learn.learner',
            'payer_id' => (string) Str::uuid(),
            'amount' => 15000,
            'currency' => 'XAF',
            'description' => 'Sekuu Learn - Comptabilite pour PME',
            'msisdn' => '+237650000000',
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function refund(ExternalCharge $charge, array $payload, ?string $idempotency = null): TestResponse
    {
        $this->flushHeaders();

        $request = $this->withToken($this->plainKey);

        if ($idempotency !== null) {
            $request = $request->withHeader('Idempotency-Key', $idempotency);
        }

        return $request->postJson("/api/v1/payments/charges/{$charge->id}/refunds", $payload);
    }

    private function endpoint(): PaymentEndpoint
    {
        return PaymentEndpoint::create([
            'organization_id' => $this->organizationId,
            'url' => 'https://learn.example.test/webhooks/payments',
            'secret' => 'whsec_test',
            'status' => PaymentEndpoint::ACTIVE,
        ]);
    }

    private function registerExternalPayable(): void
    {
        config()->set('payments.payables', [self::TYPE => ExternalPayable::class]);

        $this->app->forgetInstance(PayableRegistry::class);

        $this->app->singleton(PayableRegistry::class, function ($app): PayableRegistry {
            $registry = new PayableRegistry($app);

            foreach ((array) config('payments.payables', []) as $type => $source) {
                $registry->register($type, $source);
            }

            return $registry;
        });
    }

    /**
     * @param  list<string>  $scopes
     */
    private function issueKey(array $scopes = ['payments.charge', 'payments.read', 'payments.refund']): string
    {
        $plain = 'sk_test_'.Str::random(48);

        ApiKey::create([
            'organization_id' => $this->organizationId,
            'name' => 'Sekuu Learn',
            'prefix' => 'sk_test_',
            'key_hash' => ApiKey::hash($plain),
            'scopes' => $scopes,
            'subject_types' => [self::TYPE],
        ]);

        return $plain;
    }
}
