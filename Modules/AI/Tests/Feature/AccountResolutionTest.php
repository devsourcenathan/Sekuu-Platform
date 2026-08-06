<?php

declare(strict_types=1);

namespace Modules\AI\Tests\Feature;

use App\Platform\Contracts\AiActor;
use App\Platform\Exceptions\DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\AI\Application\Accounts\ResolveAccount;
use Modules\AI\Domain\Models\AiAccount;
use Modules\AI\Domain\Models\AiPlacement;
use Tests\TestCase;

/**
 * Quel compte exécute — et donc qui paie.
 *
 * @see docs/03-services/ai/05-providers.md
 */
final class AccountResolutionTest extends TestCase
{
    use RefreshDatabase;

    private string $orgA;

    private string $orgB;

    protected function setUp(): void
    {
        parent::setUp();

        // De vrais UUID : les colonnes le sont, et PostgreSQL ne laisse pas
        // passer « org-1 » — un détail qui ne se voit pas sur SQLite.
        $this->orgA = (string) Str::uuid();
        $this->orgB = (string) Str::uuid();
    }

    public function test_without_any_rule_the_platform_accounts_answer_in_order(): void
    {
        $this->account('secours', priority: 50);
        $this->account('principal', priority: 10);

        $accounts = app(ResolveAccount::class)->handle('summarize', AiActor::user('u', $this->orgA));

        $this->assertSame(['principal', 'secours'], array_map(fn (AiAccount $c): string => $c->slug, $accounts));
    }

    /**
     * Rendre une **liste** est ce qui permet à la bascule d'exister ; une
     * désignation explicite, elle, n'a pas de suite.
     */
    public function test_a_named_account_has_no_successor(): void
    {
        $this->account('plateforme');
        $isTheirs = $this->account('a-lui', organizationId: $this->orgA);

        $accounts = app(ResolveAccount::class)->handle('summarize', AiActor::user('u', $this->orgA), 'a-lui');

        $this->assertCount(1, $accounts);
        $this->assertTrue($accounts[0]->is($isTheirs));
    }

    /**
     * **Le test qui compte.**
     *
     * Se rabattre sur un compte de la plateforme ferait payer **nous** à la
     * place du client, sans que personne l'ait décidé — et la facture
     * n'arriverait qu'un mois plus tard.
     */
    public function test_an_unusable_named_account_fails_instead_of_falling_back_to_ours(): void
    {
        $this->account('plateforme');
        $isTheirs = $this->account('a-lui', organizationId: $this->orgA);
        $isTheirs->forceFill(['status' => AiAccount::UNVERIFIED])->save();

        try {
            app(ResolveAccount::class)->handle('summarize', AiActor::user('u', $this->orgA), 'a-lui');
            $this->fail('Un compte nommé mais hors service doit échouer.');
        } catch (DomainException $e) {
            $this->assertSame('AI_ACCOUNT_UNVERIFIED', $e->errorCode);
        }
    }

    /**
     * Une règle de placement est une **déclaration**, pas une préférence. Le
     * contraire ferait basculer chez nous, en silence, un client qui a demandé
     * que tout passe par sa clé — souvent pour une raison contractuelle.
     */
    public function test_a_placement_pointing_at_a_dead_account_fails_too(): void
    {
        $this->account('plateforme');
        $isTheirs = $this->account('a-lui', organizationId: $this->orgA);
        $isTheirs->forceFill(['status' => AiAccount::PAUSED])->save();

        AiPlacement::query()->create([
            'organization_id' => $this->orgA,
            'task' => null,
            'account_id' => $isTheirs->id,
        ]);

        $this->expectExceptionMessage('a-lui');

        app(ResolveAccount::class)->handle('summarize', AiActor::user('u', $this->orgA));
    }

    /**
     * Une règle nommant la tâche l'emporte sur l'attrape-tout : elle est la plus
     * précise, donc la plus délibérée.
     */
    public function test_a_task_rule_beats_the_catch_all(): void
    {
        $catchAll = $this->account('pour-tout', organizationId: $this->orgA);
        $summaries = $this->account('pour-resumes', organizationId: $this->orgA);

        AiPlacement::query()->create(['organization_id' => $this->orgA, 'task' => null, 'account_id' => $catchAll->id]);
        AiPlacement::query()->create(['organization_id' => $this->orgA, 'task' => 'summarize', 'account_id' => $summaries->id]);

        $this->assertSame(
            'pour-resumes',
            app(ResolveAccount::class)->handle('summarize', AiActor::user('u', $this->orgA))[0]->slug,
        );

        $this->assertSame(
            'pour-tout',
            app(ResolveAccount::class)->handle('translate', AiActor::user('u', $this->orgA))[0]->slug,
        );
    }

    /**
     * Sans ce contrôle, connaître le nom d'un compte suffirait à s'en servir —
     * et à faire porter la dépense d'autrui. Une clé d'IA fuitée se dépense.
     */
    public function test_the_account_of_another_organization_is_out_of_reach(): void
    {
        $this->account('a-eux', organizationId: $this->orgB);

        try {
            app(ResolveAccount::class)->handle('summarize', AiActor::user('u', $this->orgA), 'a-eux');
            $this->fail('Le compte d\'autrui ne doit pas être atteignable.');
        } catch (DomainException $e) {
            $this->assertSame('AI_ACCOUNT_FORBIDDEN', $e->errorCode);
        }
    }

    /**
     * Un compte de la plateforme, lui, sert tout le monde : c'est le cas
     * nominal, et il n'appartient à personne en particulier.
     */
    public function test_a_platform_account_may_be_named_by_anyone(): void
    {
        $this->account('plateforme');

        $accounts = app(ResolveAccount::class)->handle('summarize', AiActor::user('u', $this->orgB), 'plateforme');

        $this->assertSame('plateforme', $accounts[0]->slug);
    }

    /**
     * Une clé de production sur un poste de développement facturerait de vrais
     * appels à chaque exécution de la suite — la résolution le refuse comme
     * l'enregistrement le refusait.
     */
    public function test_an_account_from_another_environment_is_never_chosen(): void
    {
        $this->account('production', environment: 'production');

        $this->assertSame([], app(ResolveAccount::class)->handle('summarize', AiActor::user('u', $this->orgA)));
    }

    /**
     * Un compte d'API externe est celui de sa clé, pas celui de son
     * organisation : deux produits d'un même client ne se prêtent pas leurs
     * clés.
     */
    public function test_an_api_key_reaches_its_own_account(): void
    {
        $key = (string) Str::uuid();
        $this->account('produit', apiKeyId: $key);

        $accounts = app(ResolveAccount::class)->handle(
            'summarize',
            AiActor::apiKey($key, ['summarize'], $this->orgA),
            'produit',
        );

        $this->assertSame('produit', $accounts[0]->slug);

        $this->expectException(DomainException::class);

        app(ResolveAccount::class)->handle(
            'summarize',
            AiActor::apiKey((string) Str::uuid(), ['summarize'], $this->orgA),
            'produit',
        );
    }

    private function account(
        string $slug,
        int $priority = 100,
        ?string $organizationId = null,
        ?string $apiKeyId = null,
        ?string $environment = null,
    ): AiAccount {
        return AiAccount::query()->create([
            'slug' => $slug,
            'driver' => 'fake',
            'config' => ['base_url' => 'https://faux.exemple.cm'],
            'credentials' => ['api_key' => 'x'],
            'models' => ['fake-model'],
            'owner_organization_id' => $organizationId,
            'owner_api_key_id' => $apiKeyId,
            'environment' => $environment ?? app()->environment(),
            'status' => AiAccount::ACTIVE,
            'priority' => $priority,
            'verified_at' => now(),
        ]);
    }
}
