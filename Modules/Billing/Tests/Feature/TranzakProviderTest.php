<?php

declare(strict_types=1);

namespace Modules\Billing\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Modules\Billing\Domain\AttemptStatus;
use Modules\Billing\Domain\Money;
use Modules\Billing\Domain\Msisdn;
use Modules\Billing\Infrastructure\Providers\ChargeRequest;
use Modules\Billing\Infrastructure\Providers\TranzakProvider;
use Tests\TestCase;

/**
 * La traduction des statuts est la partie la plus sensible du module :
 * confondre un rejet avant invite avec un échec après invite autorise une
 * bascule qui double-débite le client.
 *
 * C'est pourquoi ces tests viennent avant le chemin nominal.
 *
 * @see docs/03-services/billing/05-providers.md
 */
final class TranzakProviderTest extends TestCase
{
    use RefreshDatabase;

    private TranzakProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('billing.tranzak.base_url', 'https://sandbox.dsapi.tranzak.me');
        config()->set('billing.tranzak.app_id', 'test-app');
        config()->set('billing.tranzak.app_key', 'test-key');

        Cache::flush();

        $this->provider = new TranzakProvider;
    }

    public function test_an_unconfigured_aggregator_is_never_attempted(): void
    {
        config()->set('billing.tranzak.app_id', null);

        $this->assertFalse((new TranzakProvider)->isConfigured());
    }

    /**
     * Le seul cas qui autorise une bascule : la demande a été refusée avant
     * toute sollicitation du client.
     */
    public function test_a_validation_error_is_a_rejection_and_allows_failover(): void
    {
        $this->fakeAuth();
        Http::fake(['*create-mobile-wallet-charge' => Http::response(['errorMsg' => 'Numéro invalide'], 422)]);

        $outcome = $this->provider->charge($this->request());

        $this->assertSame(AttemptStatus::Rejected, $outcome->status);
        $this->assertFalse($outcome->customerPrompted);
        $this->assertTrue($outcome->status->allowsFailover());
    }

    public function test_a_failed_authentication_is_a_rejection(): void
    {
        Http::fake(['*auth/token' => Http::response(['message' => 'refusé'], 401)]);

        $outcome = $this->provider->charge($this->request());

        $this->assertSame(AttemptStatus::Rejected, $outcome->status);
        $this->assertSame('PROVIDER_AUTH_FAILED', $outcome->failureCode);
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
     * `CANCELLED` — annulation système — est ambigu. Faute de certitude sur son
     * sens exact, il est traité comme un échec et **non** comme un rejet : cela
     * interdit la bascule.
     */
    public function test_a_system_cancellation_is_treated_as_a_failure_not_a_rejection(): void
    {
        $this->fakeCharge(['requestId' => 'req-1', 'status' => 'CANCELLED']);

        $outcome = $this->provider->charge($this->request());

        $this->assertSame(AttemptStatus::Failed, $outcome->status);
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

    public function test_a_success_carries_the_three_amounts(): void
    {
        $this->fakeCharge([
            'requestId' => 'req-1',
            'status' => 'SUCCESSFUL',
            'amount' => 45000,
            'merchantFee' => 900,
            'netAmountReceived' => 44100,
        ]);

        $outcome = $this->provider->charge($this->request());

        $this->assertSame(AttemptStatus::Succeeded, $outcome->status);
        $this->assertSame(45000, $outcome->grossAmount);
        $this->assertSame(900, $outcome->feeAmount);
        $this->assertSame(44100, $outcome->netAmount);
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
        Http::fake(['*auth/token' => Http::response(['data' => ['token' => 'jeton']])]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function fakeCharge(array $data): void
    {
        Http::fake([
            '*auth/token' => Http::response(['data' => ['token' => 'jeton']]),
            '*create-mobile-wallet-charge' => Http::response(['data' => $data]),
        ]);
    }
}
