<?php

declare(strict_types=1);

namespace Modules\Payments\Tests\Feature;

use App\Platform\Exceptions\DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Modules\Payments\Domain\AttemptStatus;
use Modules\Payments\Domain\Models\PaymentIntent;
use Modules\Payments\Infrastructure\Providers\NotchPayProvider;
use Modules\Payments\Infrastructure\Providers\TranzakProvider;
use Modules\Payments\Tests\Concerns\PaysAFakeSubject;
use Tests\TestCase;

/**
 * La bascule avec les **deux vrais adaptateurs**, pas des doubles.
 *
 * `PaymentFailoverTest` éprouve la règle sur des agrégateurs factices ; ici on
 * vérifie que les deux traductions réelles — codes HTTP chez Notch Pay, drapeau
 * `success` chez Tranzak — se combinent correctement.
 *
 * @see docs/04-decisions/adr-0008-payment-aggregators-failover.md
 */
final class RealFailoverTest extends TestCase
{
    use PaysAFakeSubject;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useFakePayments();

        config()->set('payments.notchpay.base_url', 'https://api.notchpay.co');
        config()->set('payments.notchpay.public_key', 'test_public_key');
        config()->set('payments.tranzak.base_url', 'https://sandbox.dsapi.tranzak.me');
        config()->set('payments.tranzak.app_id', 'app');
        config()->set('payments.tranzak.app_key', 'key');

        $this->useProviders([NotchPayProvider::class, TranzakProvider::class]);
    }

    /**
     * Notch Pay refuse en `422`, Tranzak prend le relais et sollicite le client.
     * Deux conventions d'erreur opposées, une seule règle.
     */
    public function test_a_notchpay_refusal_falls_over_to_tranzak(): void
    {
        Http::fake([
            'https://api.notchpay.co/payments' => Http::response(['message' => 'Invalid phone'], 422),
            '*auth/token' => Http::response(['data' => ['token' => 'jeton'], 'success' => true]),
            '*create-mobile-wallet-charge' => Http::response([
                'data' => ['requestId' => 'req-1', 'status' => 'PENDING'],
                'success' => true,
            ]),
        ]);

        $intent = $this->pay();

        $attempts = $intent->attempts()->orderBy('priority')->get();

        $this->assertCount(2, $attempts);
        $this->assertSame('notchpay', $attempts[0]->provider);
        $this->assertSame(AttemptStatus::Rejected, $attempts[0]->status);
        $this->assertSame('tranzak', $attempts[1]->provider);
        $this->assertSame(AttemptStatus::Prompted, $attempts[1]->status);
    }

    /**
     * Le refus « HTTP 200 + success:false » de Tranzak est aussi basculable que
     * le `422` de Notch Pay — c'est précisément ce que le bac à sable avait
     * démenti dans la première version.
     */
    public function test_a_tranzak_refusal_is_recognised_despite_its_http_200(): void
    {
        Http::fake([
            'https://api.notchpay.co/payments' => Http::response(['message' => 'nope'], 422),
            '*auth/token' => Http::response(['data' => ['token' => 'jeton'], 'success' => true]),
            '*create-mobile-wallet-charge' => Http::response([
                'data' => [],
                'success' => false,
                'errorMsg' => 'Mobile phone number is invalid',
                'errorCode' => 1002,
            ], 200),
        ]);

        try {
            $this->pay();
            $this->fail('Deux refus doivent lever PROVIDER_UNAVAILABLE.');
        } catch (DomainException $e) {
            $this->assertSame('PROVIDER_UNAVAILABLE', $e->errorCode);
        }

        $intent = PaymentIntent::query()->firstOrFail();

        // Les deux agrégateurs refusent : aucune invite n'est partie, donc
        // aucun client n'a été débité.
        $this->assertSame(PaymentIntent::FAILED, $intent->status);
        $this->assertSame(2, $intent->attempts()->count());
        $this->assertSame(0, $intent->attempts()->where('customer_prompted', true)->count());
    }

    /**
     * Notch Pay a sollicité le client : Tranzak n'est jamais appelé, même si
     * l'issue reste inconnue.
     */
    public function test_a_prompted_notchpay_customer_stops_the_chain(): void
    {
        Http::fake([
            'https://api.notchpay.co/payments' => Http::response([
                'transaction' => 'trx-1',
                'code' => 201,
            ], 201),
            'https://api.notchpay.co/payments/*' => Http::response([
                'transaction' => ['reference' => 'trx-1', 'status' => 'processing'],
            ], 202),
        ]);

        $intent = $this->pay();

        $this->assertSame(1, $intent->attempts()->count());
        $this->assertSame('notchpay', $intent->attempts()->firstOrFail()->provider);

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'tranzak'));
    }
}
