<?php

declare(strict_types=1);

namespace Modules\Payments\Tests\Feature;

use App\Platform\Contracts\PayableRef;
use App\Platform\Contracts\PayerContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Payments\Application\Payments\InitiatePayment;
use Modules\Payments\Domain\AttemptStatus;
use Modules\Payments\Tests\Concerns\PaysAFakeSubject;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * L'invariant que ce module met le plus en danger.
 *
 * La règle de bascule ne vit dans aucun fichier : elle est une **redondance
 * délibérée** répartie sur trois — un statut, un drapeau, et un `&&` qui les
 * combine. Une réécriture peut l'abîmer sans faire tomber un seul test
 * existant, parce que chaque test ne couvre qu'un chemin rencontré.
 *
 * Ces tests-ci couvrent la règle **exhaustivement**.
 *
 * @see docs/04-decisions/adr-0008-payment-aggregators-failover.md
 */
final class FailoverInvariantTest extends TestCase
{
    use PaysAFakeSubject;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useFakePayments();
    }

    /**
     * Table de vérité **exhaustive** de la règle de bascule.
     *
     * Itère sur `AttemptStatus::cases()` : un état ajouté demain sans entrée
     * ici fait échouer le test. Jusqu'à présent la règle n'était éprouvée que
     * sur les états effectivement rencontrés, et rien n'obligeait un nouvel
     * état à échouer fermé.
     */
    #[DataProvider('everyStatus')]
    public function test_only_a_rejection_ever_allows_failover(string $status, bool $failover, bool $solicited): void
    {
        $case = AttemptStatus::from($status);

        $this->assertSame($failover, $case->allowsFailover(), "allowsFailover() pour {$status}");
        $this->assertSame($solicited, $case->customerWasPrompted(), "customerWasPrompted() pour {$status}");

        // L'invariant, dans le seul sens où il est vrai : **basculer implique
        // que le client n'a pas été sollicité**. La réciproque est fausse et
        // doit le rester — `created` n'a sollicité personne, et n'autorise
        // pourtant aucune bascule : il n'y a encore rien à quoi échapper.
        if ($failover) {
            $this->assertFalse($solicited, "bascule autorisée alors que le client a été sollicité : {$status}");
        }
    }

    public function test_every_status_is_covered_by_the_truth_table(): void
    {
        $covered = array_column(self::everyStatus(), 0);
        $existing = array_map(static fn (AttemptStatus $c): string => $c->value, AttemptStatus::cases());

        sort($covered);
        sort($existing);

        $this->assertSame(
            $existing,
            $covered,
            'Un état de tentative a été ajouté sans entrée dans la table de vérité. '
            .'Décidez explicitement s\'il autorise une bascule — le défaut doit être non.',
        );
    }

    /**
     * @return list<array{string, bool, bool}>
     */
    public static function everyStatus(): array
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
     * Un type d'objet inconnu échoue **durement**.
     *
     * Un repli silencieux ferait aboutir un paiement que personne ne saurait
     * rattacher : de l'argent encaissé sans service rendu, exactement la
     * défaillance que ce module existe pour empêcher.
     */
    public function test_an_unregistered_payable_type_is_refused(): void
    {
        $this->expectExceptionMessage('stock.order');

        $this->app->make(InitiatePayment::class)->handle(
            subject: new PayableRef('stock.order', (string) Str::uuid()),
            payer: PayerContext::user($this->payer),
            rawMsisdn: '+237650000000',
        );
    }
}
