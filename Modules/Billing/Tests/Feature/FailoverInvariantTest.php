<?php

declare(strict_types=1);

namespace Modules\Billing\Tests\Feature;

use App\Platform\Contracts\PayableRef;
use App\Platform\Contracts\PayerContext;
use App\Platform\Contracts\PaymentSettlement;
use App\Platform\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Billing\Application\Invoicing\InvoicePayable;
use Modules\Billing\Domain\Models\Invoice;
use Modules\Billing\Tests\Concerns\BillsAnOrganization;
use Modules\Payments\Application\Payments\InitiatePayment;
use Modules\Payments\Domain\AttemptStatus;
use Modules\Payments\Domain\Models\PaymentIntent;
use Modules\Payments\Infrastructure\Providers\ChargeOutcome;
use Modules\Payments\Tests\Support\FakeProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Les invariants que l'extraction met le plus en danger.
 *
 * La règle de bascule ne vit dans aucun fichier : elle est une **redondance
 * délibérée** répartie sur trois — un statut, un drapeau, et un `&&` qui les
 * combine. Une réécriture peut l'abîmer sans faire tomber un seul test
 * existant, parce que chaque test ne couvre qu'un chemin rencontré.
 *
 * Ces tests-ci couvrent la règle **exhaustivement**, et la couture entre la
 * couche de paiement et le propriétaire de l'objet payé.
 *
 * @see docs/04-decisions/adr-0008-payment-aggregators-failover.md
 */
final class FailoverInvariantTest extends TestCase
{
    use BillsAnOrganization;
    use RefreshDatabase;

    /**
     * Table de vérité **exhaustive** de la règle de bascule.
     *
     * Itère sur `AttemptStatus::cases()` : un état ajouté demain sans entrée
     * ici fait échouer le test. Jusqu'à présent la règle n'était éprouvée que
     * sur les états effectivement rencontrés, et rien n'obligeait un nouvel
     * état à échouer fermé.
     */
    #[DataProvider('tousLesStatuts')]
    public function test_only_a_rejection_ever_allows_failover(string $statut, bool $bascule, bool $sollicite): void
    {
        $cas = AttemptStatus::from($statut);

        $this->assertSame($bascule, $cas->allowsFailover(), "allowsFailover() pour {$statut}");
        $this->assertSame($sollicite, $cas->customerWasPrompted(), "customerWasPrompted() pour {$statut}");

        // L'invariant, dans le seul sens où il est vrai : **basculer implique
        // que le client n'a pas été sollicité**. La réciproque est fausse et
        // doit le rester — `created` n'a sollicité personne, et n'autorise
        // pourtant aucune bascule : il n'y a encore rien à quoi échapper.
        if ($bascule) {
            $this->assertFalse($sollicite, "bascule autorisée alors que le client a été sollicité : {$statut}");
        }
    }

    public function test_every_status_is_covered_by_the_truth_table(): void
    {
        $couverts = array_column(self::tousLesStatuts(), 0);
        $existants = array_map(static fn (AttemptStatus $c): string => $c->value, AttemptStatus::cases());

        sort($couverts);
        sort($existants);

        $this->assertSame(
            $existants,
            $couverts,
            'Un état de tentative a été ajouté sans entrée dans la table de vérité. '
            .'Décidez explicitement s\'il autorise une bascule — le défaut doit être non.',
        );
    }

    /**
     * @return list<array{string, bool, bool}>
     */
    public static function tousLesStatuts(): array
    {
        return [
            // statut, autorise la bascule, le client a été sollicité
            ['created', false, false],
            ['rejected', true, false],
            ['prompted', false, true],
            ['processing', false, true],
            ['succeeded', false, true],
            ['failed', false, true],
            ['expired', false, true],
        ];
    }

    /**
     * La couture ajoute un point de rejeu que rien ne couvrait.
     *
     * Le rejeu d'un callback est bloqué en amont par l'unicité
     * `(provider, provider_event_id)`. Mais le propriétaire de l'objet est
     * désormais appelé par une méthode ordinaire : rien n'empêche qu'elle le
     * soit deux fois, par un callback puis par le sondage.
     */
    public function test_settling_the_same_payment_twice_pays_the_invoice_once(): void
    {
        $this->useFakeProviders();
        $this->signInAsOwner();

        FakeProvider::willReturn('primary', ChargeOutcome::succeeded('ref-1', gross: 178875));

        $invoice = $this->subscribe('business');
        $this->payInvoice($invoice);

        $invoice->refresh();

        $this->assertSame(Invoice::PAID, $invoice->status);
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
     * Un type d'objet inconnu échoue **durement**.
     *
     * Un repli silencieux ferait aboutir un paiement que personne ne saurait
     * rattacher : de l'argent encaissé sans service rendu, exactement la
     * défaillance que ce module existe pour empêcher.
     */
    public function test_an_unregistered_payable_type_is_refused(): void
    {
        $this->useFakeProviders();
        $this->signInAsOwner();

        $this->expectExceptionMessage('learn.enrollment');

        $this->app->make(InitiatePayment::class)->handle(
            subject: new PayableRef('learn.enrollment', (string) Str::uuid()),
            payer: PayerContext::organization($this->organizationId),
            rawMsisdn: '+237650000000',
        );
    }
}
