<?php

declare(strict_types=1);

namespace Modules\Payments\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Modules\Identity\Domain\Models\ApiKey;
use Modules\Payments\Application\External\ExternalPayable;
use Modules\Payments\Application\Payments\PayableRegistry;
use Modules\Payments\Domain\Models\ExternalCharge;
use Modules\Payments\Domain\Models\PaymentDelivery;
use Modules\Payments\Domain\Models\PaymentEndpoint;
use Modules\Payments\Domain\Models\PaymentIntent;
use Modules\Payments\Infrastructure\External\DeliverPaymentEvent;
use Modules\Payments\Infrastructure\Providers\ChargeOutcome;
use Modules\Payments\Tests\Concerns\PaysAFakeSubject;
use Modules\Payments\Tests\Support\FakeProvider;
use Tests\Concerns\SignsInAsOwner;
use Tests\TestCase;

/**
 * Encaisser depuis un service qui ne partage pas cette base de code.
 *
 * L'invariant éprouvé ici n'est pas « le montant ne vient jamais d'HTTP » — il
 * en vient, une fois, et c'est assumé. Il est : **seul le propriétaire de
 * l'objet nomme son prix**, borné par ce que la clé d'API autorise.
 *
 * @see docs/04-decisions/adr-0010-external-payment-api.md
 */
final class ExternalChargeTest extends TestCase
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

        $this->plainKey = $this->issueKey([self::TYPE]);
    }

    /**
     * Le parcours complet : déclarer, encaisser, livrer.
     */
    public function test_an_external_product_can_collect(): void
    {
        Bus::fake();
        FakeProvider::willReturn('primary', ChargeOutcome::succeeded('ref-1', gross: 15000, fee: 450));

        $this->endpoint();

        $this->charge((string) Str::uuid())
            ->assertStatus(202)
            ->assertJsonPath('data.status', PaymentIntent::SUCCEEDED)
            ->assertJsonPath('data.amount', 15000)
            ->assertJsonPath('data.currency_exponent', 0);

        $charge = ExternalCharge::query()->firstOrFail();

        $this->assertSame(ExternalCharge::PAID, $charge->status);
        $this->assertNotNull($charge->payment_intent_id);

        // Le registre de caisse porte le brut et la commission.
        $this->assertDatabaseHas('payment_transactions', ['type' => 'charge', 'amount' => 15000]);
        $this->assertDatabaseHas('payment_transactions', ['type' => 'fee', 'amount' => -450]);

        // Rien n'a touché la facturation.
        $this->assertDatabaseCount('invoices', 0);

        // La livraison est enfilée, jamais tentée dans la transaction
        // d'encaissement : un produit lent y tiendrait des verrous de caisse.
        $delivery = PaymentDelivery::query()->firstOrFail();

        $this->assertSame('payment.succeeded', $delivery->event_type);
        $this->assertSame(PaymentDelivery::PENDING, $delivery->status);

        Bus::assertDispatched(DeliverPaymentEvent::class);
    }

    /**
     * **L'invariant.** Une clé ne peut faire payer que les types qu'elle porte.
     *
     * Sans cette borne, une clé fuitée permettrait de déclarer 100 XAF sur la
     * facture d'abonnement de n'importe quelle organisation.
     */
    public function test_a_key_cannot_charge_a_subject_type_it_does_not_carry(): void
    {
        $this->charge((string) Str::uuid(), subjectType: 'stock.order')
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'SUBJECT_TYPE_NOT_ALLOWED');

        $this->assertDatabaseCount('external_charges', 0);
    }

    /**
     * Et surtout pas une facture, quoi qu'on demande à l'émission.
     *
     * Le prix d'une facture est produit par Billing, en base. Le laisser
     * déclarer rouvrirait exactement le trou que tout ce module ferme.
     */
    public function test_no_key_can_ever_be_issued_for_an_invoice(): void
    {
        $this->withToken($this->ownerToken)
            ->postJson('/api/v1/api-keys', [
                'name' => 'malveillante',
                'scopes' => ['payments.charge'],
                'subject_types' => ['billing.invoice'],
            ])
            ->assertStatus(422);
    }

    /**
     * Un scope de paiement sans périmètre autoriserait à déclarer un prix sans
     * dire sur quoi.
     */
    public function test_a_payment_scope_without_a_perimeter_is_refused(): void
    {
        $this->withToken($this->ownerToken)
            ->postJson('/api/v1/api-keys', [
                'name' => 'sans périmètre',
                'scopes' => ['payments.charge'],
            ])
            ->assertStatus(422);
    }

    /**
     * Un produit externe ne peut pas se réclamer d'un compte de la plateforme.
     */
    public function test_the_payer_may_never_be_a_platform_account(): void
    {
        $this->charge((string) Str::uuid(), payerType: 'identity.user')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'PAYER_TYPE_NOT_ALLOWED');
    }

    /**
     * Le montant vient de la charge déclarée, **relue en base** — jamais du
     * corps de la requête au moment de payer.
     */
    public function test_the_amount_is_read_back_from_the_declared_charge(): void
    {
        FakeProvider::willReturn('primary', ChargeOutcome::prompted('ref-1'));

        $this->charge((string) Str::uuid(), amount: 42_000)->assertStatus(202);

        $this->assertSame(42_000, PaymentIntent::query()->firstOrFail()->amount);
    }

    /**
     * Le garde-fou anti-triple-clic vaut pour un produit externe.
     */
    public function test_a_second_charge_on_the_same_subject_is_refused(): void
    {
        FakeProvider::willReturn('primary', ChargeOutcome::prompted('ref-1'));

        $subject = (string) Str::uuid();

        $this->charge($subject)->assertStatus(202);

        $this->charge($subject)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'PAYMENT_ALREADY_PENDING');
    }

    /**
     * Un refus de tous les agrégateurs ne laisse pas la charge en attente :
     * elle bloquerait indéfiniment toute nouvelle tentative sur cet objet.
     */
    public function test_a_total_refusal_closes_the_declared_charge(): void
    {
        FakeProvider::willReturn('primary', ChargeOutcome::rejected('PROVIDER_AUTH_FAILED', 'Clé refusée'));
        FakeProvider::willReturn('secondary', ChargeOutcome::rejected('PROVIDER_AUTH_FAILED', 'Panne'));

        $this->endpoint();

        $this->charge((string) Str::uuid())
            ->assertStatus(503)
            ->assertJsonPath('error.code', 'PROVIDER_UNAVAILABLE');

        $this->assertSame(ExternalCharge::FAILED, ExternalCharge::query()->firstOrFail()->status);

        // Aucun webhook : le produit a reçu le refus en réponse synchrone, et le
        // lui livrer une seconde fois lui ferait traiter deux fois le même échec.
        $this->assertDatabaseCount('payment_deliveries', 0);
    }

    /**
     * Sonder — le second des trois mécanismes, et il n'est pas optionnel.
     */
    public function test_a_product_can_poll_its_charge(): void
    {
        FakeProvider::willReturn('primary', ChargeOutcome::prompted('ref-1'));

        $chargeId = $this->charge((string) Str::uuid())->json('data.charge_id');

        $this->withToken($this->plainKey)
            ->getJson("/api/v1/payments/charges/{$chargeId}")
            ->assertOk()
            ->assertHeader('Retry-After', '5')
            ->assertJsonPath('data.status', ExternalCharge::PENDING);
    }

    /**
     * Réconcilier — le troisième, et le seul filet quand les deux autres ont
     * échoué. Il ne montre que les charges de ce produit.
     */
    public function test_reconciliation_lists_only_this_products_charges(): void
    {
        FakeProvider::willReturn('primary', ChargeOutcome::prompted('ref-1'));

        $this->charge((string) Str::uuid())->assertStatus(202);

        // La charge d'un autre produit, qui ne doit jamais apparaître.
        ExternalCharge::create([
            'organization_id' => (string) Str::uuid(),
            'subject_type' => self::TYPE,
            'subject_id' => (string) Str::uuid(),
            'payer_type' => 'learn.learner',
            'payer_id' => (string) Str::uuid(),
            'amount' => 9000,
            'currency' => 'XAF',
            'description' => 'Ailleurs',
            'status' => ExternalCharge::PENDING,
        ]);

        $this->withToken($this->plainKey)
            ->getJson('/api/v1/payments/charges')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    /**
     * Encaisser et lire sont deux droits distincts.
     */
    public function test_reading_requires_its_own_scope(): void
    {
        $writeOnly = $this->issueKey([self::TYPE], scopes: ['payments.charge']);

        $this->withToken($writeOnly)
            ->getJson('/api/v1/payments/charges')
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'INSUFFICIENT_PERMISSIONS');
    }

    /**
     * Le type externe remplace le payable factice : c'est `ExternalPayable`
     * qu'on veut éprouver, pas un double.
     */
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

    private function endpoint(): PaymentEndpoint
    {
        return PaymentEndpoint::create([
            'organization_id' => $this->organizationId,
            'url' => 'https://learn.example.test/webhooks/payments',
            'secret' => 'whsec_test',
            'status' => PaymentEndpoint::ACTIVE,
        ]);
    }

    /**
     * @param  list<string>  $subjectTypes
     * @param  list<string>  $scopes
     */
    private function issueKey(array $subjectTypes, array $scopes = ['payments.charge', 'payments.read']): string
    {
        $plain = 'sk_test_'.Str::random(48);

        ApiKey::create([
            'organization_id' => $this->organizationId,
            'name' => 'Sekuu Learn',
            'prefix' => 'sk_test_',
            'key_hash' => ApiKey::hash($plain),
            'scopes' => $scopes,
            'subject_types' => $subjectTypes,
        ]);

        return $plain;
    }

    private function charge(
        string $subjectId,
        string $subjectType = self::TYPE,
        string $payerType = 'learn.learner',
        int $amount = 15000,
    ): TestResponse {
        $this->flushHeaders();

        return $this->withToken($this->plainKey)->postJson('/api/v1/payments/charges', [
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'payer_type' => $payerType,
            'payer_id' => (string) Str::uuid(),
            'amount' => $amount,
            'currency' => 'XAF',
            'description' => 'Sekuu Learn — Comptabilité pour PME',
            'msisdn' => '+237650000000',
        ]);
    }
}
