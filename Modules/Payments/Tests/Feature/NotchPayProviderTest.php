<?php

declare(strict_types=1);

namespace Modules\Billing\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Modules\Billing\Domain\AttemptStatus;
use Modules\Billing\Domain\Models\PaymentAttempt;
use Modules\Billing\Domain\Money;
use Modules\Billing\Domain\Msisdn;
use Modules\Billing\Infrastructure\Providers\ChargeRequest;
use Modules\Billing\Infrastructure\Providers\NotchPayProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Notch Pay diffère de Tranzak sur les deux points qui gouvernent la bascule :
 * il respecte les codes HTTP, et son débit se fait en **deux appels**.
 *
 * La seconde différence rétrécit la fenêtre dangereuse : l'initialisation ne
 * sollicite jamais le client, donc même une temporisation y reste basculable.
 *
 * @see docs/04-decisions/adr-0008-payment-aggregators-failover.md
 */
final class NotchPayProviderTest extends TestCase
{
    use RefreshDatabase;

    private NotchPayProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('billing.notchpay.base_url', 'https://api.notchpay.co');
        config()->set('billing.notchpay.public_key', 'test_public_key');

        $this->provider = new NotchPayProvider;
    }

    public function test_an_unconfigured_aggregator_is_never_attempted(): void
    {
        config()->set('billing.notchpay.public_key', null);

        $this->assertFalse((new NotchPayProvider)->isConfigured());
    }

    /**
     * Contrairement à Tranzak, un refus de validation est un vrai `422`.
     */
    public function test_a_validation_error_is_a_rejection_and_allows_failover(): void
    {
        Http::fake(['*api.notchpay.co/payments' => Http::response([
            'code' => 422,
            'status' => 'Unprocessable Entity',
            'message' => 'The given data was invalid.',
            'errors' => ['amount' => ['Amount must be greater than 0']],
        ], 422)]);

        $outcome = $this->provider->charge($this->request());

        $this->assertSame(AttemptStatus::Rejected, $outcome->status);
        $this->assertTrue($outcome->status->allowsFailover());

        // Le détail par champ dit *pourquoi*, ce que `message` seul ne dit pas.
        $this->assertSame('Amount must be greater than 0', $outcome->failureReason);
    }

    public function test_a_failed_authentication_is_a_rejection(): void
    {
        Http::fake(['*api.notchpay.co/payments' => Http::response(['message' => 'Unauthorized'], 401)]);

        $outcome = $this->provider->charge($this->request());

        $this->assertSame('PROVIDER_AUTH_FAILED', $outcome->failureCode);
        $this->assertTrue($outcome->status->allowsFailover());
    }

    /**
     * **La différence structurelle avec Tranzak.**
     *
     * Une temporisation à l'initialisation reste basculable : cet appel ne
     * présente rien au client. Au pire il laisse un paiement orphelin chez
     * Notch Pay, sur lequel aucun argent ne bouge.
     */
    public function test_a_timeout_during_initialisation_still_allows_failover(): void
    {
        Http::fake(['*api.notchpay.co/payments' => fn () => throw new \RuntimeException('timeout')]);

        $outcome = $this->provider->charge($this->request());

        $this->assertSame(AttemptStatus::Rejected, $outcome->status);
        $this->assertFalse($outcome->customerPrompted);
        $this->assertTrue($outcome->status->allowsFailover());
    }

    /**
     * Au traitement, en revanche, l'incertitude redevient dangereuse : la
     * demande a pu déclencher l'invite.
     */
    public function test_a_timeout_during_processing_never_allows_failover(): void
    {
        Http::fake([
            'https://api.notchpay.co/payments' => Http::response($this->initialised(), 201),
            'https://api.notchpay.co/payments/*' => fn () => throw new \RuntimeException('timeout'),
        ]);

        $outcome = $this->provider->charge($this->request());

        $this->assertTrue($outcome->customerPrompted);
        $this->assertFalse($outcome->status->allowsFailover());
        $this->assertSame('trx-1', $outcome->providerRef);
    }

    /**
     * Un refus au traitement conserve la référence pour l'audit, sans qu'aucune
     * invite ne soit partie.
     */
    public function test_a_refusal_during_processing_keeps_the_reference_and_allows_failover(): void
    {
        Http::fake([
            'https://api.notchpay.co/payments' => Http::response($this->initialised(), 201),
            'https://api.notchpay.co/payments/*' => Http::response(['message' => 'Invalid channel'], 422),
        ]);

        $outcome = $this->provider->charge($this->request());

        $this->assertSame(AttemptStatus::Rejected, $outcome->status);
        $this->assertFalse($outcome->customerPrompted);
        $this->assertSame('trx-1', $outcome->providerRef);
    }

    public function test_a_server_error_during_processing_is_never_a_rejection(): void
    {
        Http::fake([
            'https://api.notchpay.co/payments' => Http::response($this->initialised(), 201),
            'https://api.notchpay.co/payments/*' => Http::response('', 500),
        ]);

        $outcome = $this->provider->charge($this->request());

        $this->assertTrue($outcome->customerPrompted);
        $this->assertFalse($outcome->status->allowsFailover());
    }

    /**
     * La documentation est explicite : après le traitement, « the customer
     * receives a prompt on their mobile device ».
     */
    public function test_processing_means_the_customer_was_prompted(): void
    {
        $this->fakeCharge('processing');

        $outcome = $this->provider->charge($this->request());

        $this->assertSame(AttemptStatus::Prompted, $outcome->status);
        $this->assertFalse($outcome->status->allowsFailover());
    }

    /**
     * Forme réelle d'un paiement abouti dans le bac à sable : `fees` est un
     * **tableau**, vide, et il n'y a aucun montant net.
     *
     * La première version lisait `fee` et `amount_received` comme des scalaires.
     * Aucun des deux n'existe.
     */
    public function test_a_complete_payment_in_the_sandbox_reports_no_fee(): void
    {
        $this->fakeProcessed([
            'reference' => 'trx-1',
            'status' => 'complete',
            'amount' => 100,
            'fees' => [],
            'amounts' => ['total' => 100, 'converted' => 100, 'currency' => 'XAF', 'rate' => null],
            'sandbox' => 1,
        ]);

        $outcome = $this->provider->charge($this->request());

        $this->assertSame(AttemptStatus::Succeeded, $outcome->status);
        $this->assertSame(100, $outcome->grossAmount);

        // Commission inconnue plutôt qu'inventée : la facture se règle sur le
        // brut, et l'absence se voit au registre.
        $this->assertNull($outcome->feeAmount);
        $this->assertNull($outcome->netAmount);
    }

    /**
     * Le net n'est pas renvoyé par Notch Pay : il est **déduit**, jamais
     * inventé.
     */
    public function test_a_populated_fee_array_is_summed_and_the_net_deduced(): void
    {
        $this->fakeProcessed([
            'reference' => 'trx-1',
            'status' => 'complete',
            'amount' => 45000,
            'fees' => [['amount' => 900], ['amount' => 100]],
        ]);

        $outcome = $this->provider->charge($this->request());

        $this->assertSame(45000, $outcome->grossAmount);
        $this->assertSame(1000, $outcome->feeAmount);
        $this->assertSame(44000, $outcome->netAmount);
    }

    /**
     * Le montant brut se lit aussi dans `amounts.total` si `amount` manque.
     */
    public function test_the_gross_amount_falls_back_to_the_amounts_section(): void
    {
        $this->fakeProcessed([
            'reference' => 'trx-1',
            'status' => 'complete',
            'amounts' => ['total' => 100, 'currency' => 'XAF'],
        ]);

        $this->assertSame(100, $this->provider->charge($this->request())->grossAmount);
    }

    public function test_a_cancelled_payment_does_not_allow_failover(): void
    {
        $this->fakeCharge('canceled');

        $outcome = $this->provider->charge($this->request());

        $this->assertSame(AttemptStatus::Failed, $outcome->status);
        $this->assertFalse($outcome->status->allowsFailover());
    }

    /**
     * Ne jamais supposer qu'un statut qu'on ne comprend pas est inoffensif.
     */
    public function test_an_unknown_status_is_treated_as_prompted(): void
    {
        $this->fakeCharge('something_new');

        $outcome = $this->provider->charge($this->request());

        $this->assertTrue($outcome->customerPrompted);
        $this->assertFalse($outcome->status->allowsFailover());
    }

    /**
     * **Le piège le plus coûteux de cet adaptateur.**
     *
     * La documentation montre `transaction` comme une chaîne ; le bac à sable
     * renvoie un objet. N'en comprendre qu'une ferait passer une réponse
     * acceptée pour une absence de référence — donc pour un rejet, donc pour un
     * cas **basculable**, alors qu'un paiement existe.
     */
    public function test_both_shapes_of_the_transaction_field_are_understood(): void
    {
        $this->fakeCharge('processing');
        $this->assertSame('trx-1', $this->provider->charge($this->request())->providerRef);

        $this->fakeProcessed(['reference' => 'trx-1', 'status' => 'processing']);
        $this->assertSame('trx-1', $this->provider->charge($this->request())->providerRef);
    }

    /**
     * Les cinq numéros déterministes du bac à sable produisent exactement ces
     * statuts, constatés contre l'API réelle. Aucun n'autorise de bascule : à ce
     * stade le traitement a été accepté, donc le client a été sollicité.
     */
    #[DataProvider('sandboxOutcomes')]
    public function test_the_sandbox_outcome_matrix_never_allows_failover(string $status, string $expected): void
    {
        $this->fakeProcessed(['reference' => 'trx-1', 'status' => $status]);

        $outcome = $this->provider->charge($this->request());

        $this->assertSame($expected, $outcome->status->value);
        $this->assertTrue($outcome->customerPrompted);
        $this->assertFalse($outcome->status->allowsFailover());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function sandboxOutcomes(): array
    {
        return [
            'succès' => ['complete', 'succeeded'],
            'échec' => ['failed', 'failed'],
            'annulé' => ['canceled', 'failed'],
            'temporisation' => ['expired', 'failed'],
            'en cours' => ['processing', 'prompted'],
        ];
    }

    /**
     * Au sondage, un refus ne rétrograde jamais une tentative en `rejected` :
     * l'invite est peut-être partie.
     */
    public function test_polling_a_failure_never_downgrades_to_rejected(): void
    {
        Http::fake(['*' => Http::response(['message' => 'Not found'], 404)]);

        $attempt = new PaymentAttempt(['provider_ref' => 'trx-1']);

        $this->assertFalse($this->provider->poll($attempt)->status->allowsFailover());
    }

    /**
     * Le canal suit le réseau du payeur : un numéro MTN ne se débite pas sur le
     * canal Orange. Et le montant part sans conversion — 45 000 XAF vaut 45000.
     */
    public function test_the_channel_follows_the_operator_and_the_amount_is_not_converted(): void
    {
        $this->fakeCharge('processing');

        $this->provider->charge($this->request());

        Http::assertSent(function ($request): bool {
            if ($request->url() === 'https://api.notchpay.co/payments') {
                return $request['amount'] === 45000
                    && $request['currency'] === 'XAF'
                    && $request['reference'] === 'SKUTESTREFERENCE1';
            }

            return $request['channel'] === 'cm.mtn';
        });
    }

    public function test_the_public_key_is_sent_without_a_bearer_prefix(): void
    {
        $this->fakeCharge('processing');

        $this->provider->charge($this->request());

        Http::assertSent(
            fn ($request) => $request->header('Authorization')[0] === 'test_public_key'
        );
    }

    private function request(): ChargeRequest
    {
        return new ChargeRequest(
            money: Money::of(45000, 'XAF'),
            msisdn: Msisdn::parse('+237650000000'),
            merchantReference: 'SKUTESTREFERENCE1',
            description: 'Facture de test',
        );
    }

    /**
     * La documentation montre `transaction` comme une **chaîne**.
     *
     * @return array<string, mixed>
     */
    private function initialised(): array
    {
        return [
            'status' => 'Accepted',
            'message' => 'Payment initialized',
            'code' => 201,
            'transaction' => 'trx-1',
            'authorization_url' => 'https://pay.notchpay.co/pay_123',
        ];
    }

    /**
     * Le bac à sable renvoie en réalité un **objet**.
     *
     * Les deux formes doivent être lues : n'en comprendre qu'une ferait passer
     * une réponse acceptée pour une absence de référence, donc pour un rejet —
     * et un rejet est basculable.
     *
     * @return array<string, mixed>
     */
    private function initialisedAsObject(): array
    {
        return [
            'status' => 'Accepted',
            'message' => 'Payment initialized',
            'code' => 201,
            'transaction' => [
                'reference' => 'trx-1',
                'merchant_reference' => 'SKUTESTREFERENCE1',
                'trxref' => 'SKUTESTREFERENCE1',
                'status' => 'pending',
                'amount' => 100,
                'sandbox' => true,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $transaction
     */
    private function fakeProcessed(array $transaction): void
    {
        Http::fake([
            'https://api.notchpay.co/payments' => Http::response($this->initialisedAsObject(), 201),
            'https://api.notchpay.co/payments/*' => Http::response(['transaction' => $transaction], 202),
        ]);
    }

    private function fakeCharge(string $status): void
    {
        Http::fake([
            'https://api.notchpay.co/payments' => Http::response($this->initialised(), 201),
            'https://api.notchpay.co/payments/*' => Http::response([
                'status' => 'Accepted',
                'message' => 'Payment processing initiated',
                'code' => 202,
                'transaction' => ['reference' => 'trx-1', 'trxref' => 'SKUTESTREFERENCE1', 'status' => $status],
            ], 202),
        ]);
    }
}
