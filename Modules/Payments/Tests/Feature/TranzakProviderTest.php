<?php

declare(strict_types=1);

namespace Modules\Payments\Tests\Feature;

use App\Platform\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Modules\Payments\Domain\AttemptStatus;
use Modules\Payments\Domain\Models\PaymentAttempt;
use Modules\Payments\Domain\Msisdn;
use Modules\Payments\Infrastructure\Providers\ChargeRequest;
use Modules\Payments\Infrastructure\Providers\TranzakProvider;
use Tests\TestCase;

/**
 * La traduction des statuts est la partie la plus sensible du module :
 * confondre un rejet avant invite avec un échec après invite autorise une
 * bascule qui double-débite le client.
 *
 * C'est pourquoi ces tests viennent avant le chemin nominal.
 *
 * @see docs/03-services/payments/05-providers.md
 */
final class TranzakProviderTest extends TestCase
{
    use RefreshDatabase;

    private TranzakProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('payments.tranzak.base_url', 'https://sandbox.dsapi.tranzak.me');
        config()->set('payments.tranzak.app_id', 'test-app');
        config()->set('payments.tranzak.app_key', 'test-key');

        Cache::flush();

        $this->provider = new TranzakProvider;
    }

    public function test_an_unconfigured_aggregator_is_never_attempted(): void
    {
        config()->set('payments.tranzak.app_id', null);

        $this->assertFalse((new TranzakProvider)->isConfigured());
    }

    /**
     * **Le statut HTTP ne fait pas autorité chez Tranzak.**
     *
     * Un refus de validation arrive en `HTTP 200` avec `success: false` —
     * constaté contre le bac à sable. Classer par code HTTP rendait tous ces
     * refus « incertains », donc non basculables : la mécanique de bascule
     * était inerte.
     *
     * Réponse réelle du bac à sable pour un numéro non attribué.
     */
    public function test_a_validation_refusal_arrives_as_http_200_and_allows_failover(): void
    {
        $this->fakeAuth();
        Http::fake(['*create-mobile-wallet-charge' => Http::response([
            'data' => [],
            'success' => false,
            'errorMsg' => 'Mobile phone number is invalid: +237000000000',
            'errorCode' => 1002,
        ], 200)]);

        $outcome = $this->provider->charge($this->request());

        $this->assertSame(AttemptStatus::Rejected, $outcome->status);
        $this->assertFalse($outcome->customerPrompted);
        $this->assertTrue($outcome->status->allowsFailover());
    }

    /**
     * Autre réponse réelle du bac à sable : `errorCode` vaut `null`.
     *
     * C'est pourquoi la décision ne repose pas sur une liste de codes — ils
     * vont de `1002` à `40022` en passant par `0` et `null`, et une liste
     * incomplète échouerait « ouvert ».
     */
    public function test_a_refusal_without_an_error_code_is_still_a_rejection(): void
    {
        $this->fakeAuth();
        Http::fake(['*create-mobile-wallet-charge' => Http::response([
            'data' => [],
            'success' => false,
            'errorMsg' => 'Amount the must be greater than zero.',
            'errorCode' => null,
        ], 200)]);

        $this->assertTrue($this->provider->charge($this->request())->status->allowsFailover());
    }

    /**
     * Un refus **accompagné** d'une référence signifie qu'une transaction
     * existe : le client a pu être sollicité, donc aucune bascule.
     */
    public function test_a_refusal_carrying_a_reference_blocks_failover(): void
    {
        $this->fakeAuth();
        Http::fake(['*create-mobile-wallet-charge' => Http::response([
            'data' => ['requestId' => 'req-1'],
            'success' => false,
            'errorMsg' => 'Rejeté',
        ], 200)]);

        $outcome = $this->provider->charge($this->request());

        $this->assertSame(AttemptStatus::Failed, $outcome->status);
        $this->assertFalse($outcome->status->allowsFailover());
    }

    /**
     * L'authentification échoue elle aussi en `HTTP 200` : `->throw()` ne
     * l'aurait jamais vue. Réponse réelle du bac à sable.
     */
    public function test_a_failed_authentication_arrives_as_http_200_and_is_a_rejection(): void
    {
        Http::fake(['*auth/token' => Http::response([
            'data' => [],
            'errorMsg' => 'Authentication Error',
            'errorCode' => 40022,
            'success' => false,
        ], 200)]);

        $outcome = $this->provider->charge($this->request());

        $this->assertSame(AttemptStatus::Rejected, $outcome->status);
        $this->assertSame('PROVIDER_AUTH_FAILED', $outcome->failureCode);
    }

    /**
     * Au sondage, `success: false` n'a pas le même sens qu'au débit : Tranzak
     * ne sait pas répondre sur cette transaction, il n'a pas refusé une
     * demande. Rétrograder la tentative en `rejected` rouvrirait la bascule
     * alors que l'invite est peut-être partie.
     */
    public function test_polling_an_unknown_reference_never_downgrades_to_rejected(): void
    {
        $this->fakeAuth();
        Http::fake(['*request/details' => Http::response([
            'data' => [],
            'success' => false,
            'errorMsg' => 'The requested resource was not found.',
            'errorCode' => 0,
        ], 200)]);

        $attempt = new PaymentAttempt(['provider_ref' => 'req-1']);

        $outcome = $this->provider->poll($attempt);

        $this->assertFalse($outcome->status->allowsFailover());
        $this->assertTrue($outcome->customerPrompted);
    }

    /**
     * Le cas le plus dangereux : on ignore si la demande a atteint Tranzak.
     * Traité comme « invite partie » — le défaut penche du côté qui ne débite
     * pas deux fois.
     */
    public function test_a_server_error_is_never_a_rejection(): void
    {
        $this->fakeAuth();
        Http::fake(['*create-mobile-wallet-charge' => Http::response('', 500)]);

        $outcome = $this->provider->charge($this->request());

        $this->assertTrue($outcome->customerPrompted);
        $this->assertFalse($outcome->status->allowsFailover());
    }

    public function test_a_network_failure_is_never_a_rejection(): void
    {
        $this->fakeAuth();
        Http::fake(['*create-mobile-wallet-charge' => fn () => throw new \RuntimeException('timeout')]);

        $outcome = $this->provider->charge($this->request());

        $this->assertTrue($outcome->customerPrompted);
        $this->assertFalse($outcome->status->allowsFailover());
    }

    public function test_pending_means_the_customer_was_prompted(): void
    {
        $this->fakeCharge(['requestId' => 'req-1', 'status' => 'PENDING']);

        $outcome = $this->provider->charge($this->request());

        $this->assertSame(AttemptStatus::Prompted, $outcome->status);
        $this->assertSame('req-1', $outcome->providerRef);
        $this->assertFalse($outcome->status->allowsFailover());
    }

    /**
     * Le seul statut de tout l'écosystème qui **prouve** que le client a été
     * sollicité : il n'aurait pas pu annuler sans avoir reçu l'invite.
     */
    public function test_a_payer_cancellation_proves_the_prompt_and_blocks_failover(): void
    {
        $this->fakeCharge(['requestId' => 'req-1', 'status' => 'CANCELLED_BY_PAYER']);

        $outcome = $this->provider->charge($this->request());

        $this->assertSame(AttemptStatus::Failed, $outcome->status);
        $this->assertTrue($outcome->customerPrompted);
        $this->assertFalse($outcome->status->allowsFailover());
    }

    /**
     * `CANCELLED` reste traité comme un échec, jamais comme un rejet.
     *
     * Le bac à sable a montré qu'il ne se produit **pas** sur ce flux : une
     * annulation ressort en `FAILED` / `TXN_CANCELLED`. La correspondance est
     * conservée par prudence — un statut documenté qu'on n'a jamais observé
     * reste un statut possible.
     */
    public function test_a_system_cancellation_is_treated_as_a_failure_not_a_rejection(): void
    {
        $this->fakeCharge(['requestId' => 'req-1', 'status' => 'CANCELLED']);

        $outcome = $this->provider->charge($this->request());

        $this->assertSame(AttemptStatus::Failed, $outcome->status);
        $this->assertFalse($outcome->status->allowsFailover());
    }

    /**
     * Forme réelle d'une annulation dans le bac à sable : `success: true` au
     * sommet, statut `FAILED`, et le motif dans `errorMessage` — pas dans
     * `statusMessage`, qui n'existe pas.
     */
    public function test_a_cancelled_request_reports_its_real_reason(): void
    {
        $this->fakeCharge([
            'requestId' => 'req-1',
            'status' => 'FAILED',
            'transactionStatus' => 'FAILED',
            'errorCode' => 3008,
            'errorMessage' => 'TXN_CANCELLED',
        ]);

        $outcome = $this->provider->charge($this->request());

        $this->assertSame(AttemptStatus::Failed, $outcome->status);
        $this->assertSame('TXN_CANCELLED', $outcome->failureReason);
        $this->assertFalse($outcome->status->allowsFailover());
    }

    /**
     * Ne jamais supposer qu'un statut qu'on ne comprend pas est inoffensif.
     */
    public function test_an_unknown_status_is_treated_as_prompted(): void
    {
        $this->fakeCharge(['requestId' => 'req-1', 'status' => 'SOMETHING_NEW']);

        $outcome = $this->provider->charge($this->request());

        $this->assertTrue($outcome->customerPrompted);
        $this->assertFalse($outcome->status->allowsFailover());
    }

    /**
     * Forme réelle d'un paiement abouti dans le bac à sable.
     *
     * **La commission vit dans `merchant`, pas à la racine.** La première
     * version la cherchait au sommet : elle ressortait toujours nulle, et la
     * ligne `fee` du registre n'était jamais écrite — toute la séparation
     * brut / net du module restait inerte.
     *
     * Et surtout pas `payer.fee`, qui porte la part éventuellement mise à la
     * charge du client : ici 0, contre 3 côté marchand. Lire l'une pour l'autre
     * enregistrerait un chiffre faux.
     */
    public function test_a_success_reads_the_fee_from_the_merchant_section(): void
    {
        $this->fakeCharge([
            'requestId' => 'req-1',
            'status' => 'SUCCESSFUL',
            'amount' => 100,
            'mchTransactionRef' => 'SKUTESTREFERENCE1',
            'payer' => ['amount' => 100, 'fee' => 0, 'netAmountPaid' => 100],
            'merchant' => ['amount' => 100, 'fee' => 3, 'netAmountReceived' => 97],
        ]);

        $outcome = $this->provider->charge($this->request());

        $this->assertSame(AttemptStatus::Succeeded, $outcome->status);
        $this->assertSame(100, $outcome->grossAmount);
        $this->assertSame(3, $outcome->feeAmount);
        $this->assertSame(97, $outcome->netAmount);
    }

    /**
     * Un paiement abouti sans section `merchant` ne doit pas faire échouer la
     * lecture : la facture se règle sur le brut, la commission est simplement
     * inconnue.
     */
    public function test_a_success_without_a_merchant_section_still_settles(): void
    {
        $this->fakeCharge(['requestId' => 'req-1', 'status' => 'SUCCESSFUL', 'amount' => 100]);

        $outcome = $this->provider->charge($this->request());

        $this->assertSame(AttemptStatus::Succeeded, $outcome->status);
        $this->assertSame(100, $outcome->grossAmount);
        $this->assertNull($outcome->feeAmount);
    }

    /**
     * Sans référence, plus rien n'est retrouvable. Traité comme incertain,
     * jamais comme un rejet.
     */
    public function test_an_accepted_response_without_a_reference_is_uncertain(): void
    {
        $this->fakeCharge(['status' => 'PENDING']);

        $outcome = $this->provider->charge($this->request());

        $this->assertTrue($outcome->customerPrompted);
        $this->assertFalse($outcome->status->allowsFailover());
    }

    /**
     * Le montant part en plus petite unité, sans conversion : 45 000 XAF vaut
     * 45000, pas 4500000.
     */
    public function test_the_amount_is_sent_without_conversion(): void
    {
        $this->fakeCharge(['requestId' => 'req-1', 'status' => 'PENDING']);

        $this->provider->charge($this->request());

        Http::assertSent(function ($request): bool {
            if (! str_contains($request->url(), 'create-mobile-wallet-charge')) {
                return true;
            }

            return $request['amount'] === 45000
                && $request['currencyCode'] === 'XAF'
                && $request['mobileWalletNumber'] === '+237650000000';
        });
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

    private function fakeAuth(): void
    {
        // Forme réelle du bac à sable : enveloppe `data`, `success` au sommet,
        // `expiresIn` de 7200 s — d'où la mise en cache à 90 min, soit 75 % de
        // la validité comme le recommande Tranzak.
        Http::fake(['*auth/token' => Http::response([
            'data' => ['token' => 'jeton', 'expiresIn' => 7200, 'scope' => 'collections'],
            'success' => true,
        ])]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function fakeCharge(array $data): void
    {
        Http::fake([
            '*auth/token' => Http::response(['data' => ['token' => 'jeton']]),
            '*create-mobile-wallet-charge' => Http::response(['data' => $data, 'success' => true]),
        ]);
    }
}
