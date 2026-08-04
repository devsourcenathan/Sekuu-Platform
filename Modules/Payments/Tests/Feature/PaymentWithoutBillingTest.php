<?php

declare(strict_types=1);

namespace Modules\Payments\Tests\Feature;

use App\Platform\Exceptions\DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Payments\Application\Payments\ReconcilePayments;
use Modules\Payments\Domain\AttemptStatus;
use Modules\Payments\Domain\Models\PaymentIntent;
use Modules\Payments\Domain\Models\PaymentTransaction;
use Modules\Payments\Infrastructure\Providers\ChargeOutcome;
use Modules\Payments\Tests\Concerns\PaysAFakeSubject;
use Modules\Payments\Tests\Support\FakePayable;
use Modules\Payments\Tests\Support\FakeProvider;
use Tests\TestCase;

/**
 * **La démonstration de ce pour quoi l'extraction a été faite.**
 *
 * Un paiement complet — cotation, débit, bascule, encaissement, registre —
 * pour un objet qui n'est ni une facture ni un abonnement. Aucune ligne dans
 * `invoices`, aucune dans `subscriptions`.
 *
 * @see docs/04-decisions/adr-0009-payments-module-extraction.md
 */
final class PaymentWithoutBillingTest extends TestCase
{
    use PaysAFakeSubject;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useFakePayments();
    }

    /**
     * Le parcours complet, sans Billing.
     */
    public function test_a_learner_pays_without_any_invoice_or_subscription(): void
    {
        FakeProvider::willReturn('primary', ChargeOutcome::succeeded('ref-1', gross: 15000, fee: 450));

        $inscription = (string) Str::uuid();

        $intent = $this->pay($inscription);

        $this->assertSame(PaymentIntent::SUCCEEDED, $intent->status);
        $this->assertSame(15000, $intent->amount);

        // Le payeur est une **personne**, pas une organisation.
        $this->assertSame(PaymentIntent::PAYER_USER, $intent->payer_type);

        // Le propriétaire de l'objet a été prévenu, dans la transaction.
        $this->assertSame([$inscription], FakePayable::$regles);

        // Le registre de caisse porte le brut et la commission.
        $this->assertDatabaseHas('payment_transactions', ['type' => 'charge', 'amount' => 15000]);
        $this->assertDatabaseHas('payment_transactions', ['type' => 'fee', 'amount' => -450]);

        // **Et rien de tout cela n'a touché la facturation.**
        $this->assertDatabaseCount('invoices', 0);
        $this->assertDatabaseCount('subscriptions', 0);
        $this->assertDatabaseCount('credit_entries', 0);
    }

    /**
     * Le montant vient du propriétaire, jamais de l'appelant — y compris pour
     * un produit qui n'est pas Billing.
     */
    public function test_the_amount_comes_from_the_owner(): void
    {
        FakePayable::$prix = 42_000;
        FakeProvider::willReturn('primary', ChargeOutcome::prompted('ref-1'));

        $this->assertSame(42_000, $this->pay((string) Str::uuid())->amount);
    }

    /**
     * Le propriétaire refuse, et son code remonte tel quel : Payments ne
     * réinterprète pas une décision qu'il n'a pas prise.
     */
    public function test_the_owner_can_refuse(): void
    {
        FakePayable::$refus = ['ENROLLMENT_CLOSED', 'Les inscriptions sont closes.'];

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Les inscriptions sont closes.');

        $this->pay((string) Str::uuid());
    }

    /**
     * Le garde-fou anti-triple-clic vaut pour un objet quelconque.
     *
     * C'est le trou que l'extraction a comblé : l'index portait auparavant sur
     * `invoice_id` et excluait explicitement les paiements sans facture.
     */
    public function test_a_second_payment_on_the_same_subject_is_refused(): void
    {
        FakeProvider::willReturn('primary', ChargeOutcome::prompted('ref-1'));

        $inscription = (string) Str::uuid();

        $this->pay($inscription);

        $this->expectException(DomainException::class);
        $this->pay($inscription);
    }

    /**
     * La bascule fonctionne à l'identique hors facturation.
     */
    public function test_failover_works_the_same_without_billing(): void
    {
        FakeProvider::willReturn('primary', ChargeOutcome::rejected('PROVIDER_AUTH_FAILED', 'Clé refusée'));
        FakeProvider::willReturn('secondary', ChargeOutcome::prompted('ref-secondary'));

        $intent = $this->pay((string) Str::uuid());

        $this->assertSame(['primary', 'secondary'], FakeProvider::$charged);

        $tentatives = $intent->attempts()->orderBy('priority')->get();

        $this->assertSame(AttemptStatus::Rejected, $tentatives[0]->status);
        $this->assertSame(AttemptStatus::Prompted, $tentatives[1]->status);
    }

    /**
     * Le sondage constate un paiement sans rien savoir de ce qu'il règle.
     */
    public function test_polling_settles_a_payment_it_knows_nothing_about(): void
    {
        FakeProvider::willReturn('primary', ChargeOutcome::prompted('ref-1'));

        $inscription = (string) Str::uuid();
        $intent = $this->pay($inscription);

        $this->assertSame(PaymentIntent::PENDING, $intent->status);
        $this->assertSame([], FakePayable::$regles);

        FakeProvider::willPoll('primary', ChargeOutcome::succeeded('ref-1', gross: 15000));

        $this->app->make(ReconcilePayments::class)->handle();

        $this->assertSame(PaymentIntent::SUCCEEDED, $intent->fresh()->status);
        $this->assertSame([$inscription], FakePayable::$regles);
        $this->assertSame(1, PaymentTransaction::query()->where('type', 'charge')->count());
    }

    /**
     * Une tentative morte avant l'appel n'est plus abandonnée.
     *
     * Le processus meurt entre l'enregistrement de la tentative et l'appel de
     * débit : elle reste en `created`, sans référence agrégateur. Elle n'était
     * ni sondée ni expirée, et occupait indéfiniment l'unicité « une seule
     * tentative vivante par intention » — alors que le client avait peut-être
     * été sollicité.
     */
    public function test_an_attempt_that_died_before_the_call_is_still_reconciled(): void
    {
        FakeProvider::willReturn('primary', ChargeOutcome::prompted('ref-1'));

        $intent = $this->pay((string) Str::uuid());
        $attempt = $intent->attempts()->firstOrFail();

        // On simule la mort du processus : la tentative n'a jamais rien reçu.
        $attempt->forceFill([
            'status' => AttemptStatus::Created,
            'provider_ref' => null,
            'customer_prompted' => false,
        ])->save();

        // L'agrégateur retrouve la transaction par **notre** référence.
        FakeProvider::willPoll('primary', ChargeOutcome::succeeded('ref-retrouvee', gross: 15000));

        $this->app->make(ReconcilePayments::class)->handle();

        $this->assertSame(AttemptStatus::Succeeded, $attempt->fresh()->status);
        $this->assertSame('ref-retrouvee', $attempt->fresh()->provider_ref);
    }
}
