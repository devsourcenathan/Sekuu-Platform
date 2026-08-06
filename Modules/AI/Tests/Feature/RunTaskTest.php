<?php

declare(strict_types=1);

namespace Modules\AI\Tests\Feature;

use App\Platform\Contracts\AiActor;
use App\Platform\Contracts\BillingContract;
use App\Platform\Contracts\PlanLimit;
use App\Platform\Exceptions\DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\AI\Application\Generation\RunTask;
use Modules\AI\Application\Generation\TaskRequest;
use Modules\AI\Domain\Models\AiAccount;
use Modules\AI\Domain\Models\AiContent;
use Modules\AI\Domain\Models\AiGeneration;
use Modules\AI\Infrastructure\Drivers\FakeDriver;
use Tests\TestCase;

/**
 * Exécuter une tâche : ce qu'on paie, ce qu'on garde, et quand on va ailleurs.
 *
 * @see docs/04-decisions/adr-0016-ai-spend-and-privacy.md
 */
final class RunTaskTest extends TestCase
{
    use RefreshDatabase;

    private string $org;

    protected function setUp(): void
    {
        parent::setUp();

        FakeDriver::reset();
        $this->org = (string) Str::uuid();
    }

    public function test_a_task_runs_and_is_written_down(): void
    {
        $this->account('principal');

        $generation = $this->execute('prompt-fast', ['prompt' => 'Bonjour.']);

        $this->assertSame(AiGeneration::SUCCEEDED, $generation->status);
        $this->assertSame('réponse factice', AiContent::query()->find($generation->id)?->output);
        $this->assertSame(100, $generation->input_tokens);
        $this->assertSame(50, $generation->output_tokens);
        $this->assertSame(1, $generation->attempts);
        $this->assertNotNull($generation->completed_at);
    }

    /**
     * Le prompt n'est **jamais** écrit sans que la tâche l'ait déclaré.
     *
     * Un registre de prompts concentrerait en clair ce que tous les produits ont
     * de plus sensible — un dossier médical chez SOS Clinique, un contrat.
     */
    public function test_the_prompt_is_never_kept_by_default(): void
    {
        $this->account('principal');

        $generation = $this->execute('prompt-fast', ['prompt' => 'Numéro de dossier 4471, diabète de type 2.']);

        $contenu = AiContent::query()->find($generation->id);

        // La sortie survit brievement — un sondage doit pouvoir la lire. Le
        // prompt, lui, n'est pas ecrit du tout : `null` dit « on ne l'a pas
        // gardee », ce qu'une chaine vide ne dirait pas.
        $this->assertNotNull($contenu);
        $this->assertNull($contenu->input);
        $this->assertNotNull($contenu->output);
        $this->assertTrue($contenu->expires_at->lessThanOrEqualTo(now()->addHours(24)));

        // L'empreinte suffit à l'idempotence, et elle ne rend rien.
        $this->assertSame(64, strlen((string) $generation->input_hash));
        $this->assertStringNotContainsString('4471', (string) $generation->input_hash);
    }

    /**
     * **Le test qui compte de ce fichier.**
     *
     * Un délai dépassé signifie que la requête est partie et que le modèle
     * produit peut-être encore. Réessayer ailleurs paie deux fois et rend une
     * réponse différente de celle qui arrivait.
     *
     * C'est l'ADR-0008 transposée : *l'incertitude compte comme un appel
     * abouti.*
     */
    public function test_a_timeout_never_moves_to_another_account(): void
    {
        $this->account('principal', priority: 10);
        $this->account('secours', priority: 20);

        FakeDriver::failOnce('AI_PROVIDER_TIMEOUT', 504);

        $generation = $this->execute('prompt-fast', ['prompt' => 'Bonjour.']);

        $this->assertSame(AiGeneration::FAILED, $generation->status);
        $this->assertSame('AI_PROVIDER_TIMEOUT', $generation->failure_code);
        $this->assertSame(1, FakeDriver::$calls, 'Un délai dépassé ne doit pas produire un second appel.');
    }

    /**
     * Un `429` n'a rien produit : aller ailleurs ne paie rien deux fois.
     */
    public function test_a_rate_limit_moves_to_the_next_account(): void
    {
        $this->account('principal', priority: 10);
        $this->account('secours', priority: 20);

        FakeDriver::failOnce('AI_RATE_LIMITED');

        $generation = $this->execute('prompt-fast', ['prompt' => 'Bonjour.']);

        $this->assertSame(AiGeneration::SUCCEEDED, $generation->status);
        $this->assertSame('secours', $generation->account->slug);
        $this->assertSame(2, $generation->attempts);
    }

    /**
     * Un refus de modération contourné en changeant de fournisseur serait un
     * contournement réussi — et personne n'en veut.
     */
    public function test_a_moderation_refusal_stops_everywhere(): void
    {
        $this->account('principal', priority: 10);
        $this->account('secours', priority: 20);

        FakeDriver::failOnce('CONTENT_FLAGGED', 422);

        $generation = $this->execute('prompt-fast', ['prompt' => 'Quelque chose de refusé.']);

        $this->assertSame('CONTENT_FLAGGED', $generation->failure_code);
        $this->assertSame(1, FakeDriver::$calls);
    }

    /**
     * Une clé refusée sort le compte du service tout de suite : attendre
     * l'épreuve de la nuit ferait échouer chaque appel jusque-là, un par un,
     * chez le même compte.
     */
    public function test_a_rejected_key_takes_the_account_out_on_the_spot(): void
    {
        $mauvais = $this->account('mauvais', priority: 10);
        $this->account('bon', priority: 20);

        FakeDriver::failOnce('AI_CREDENTIALS_REJECTED');

        $generation = $this->execute('prompt-fast', ['prompt' => 'Bonjour.']);

        $this->assertSame(AiGeneration::SUCCEEDED, $generation->status);
        $this->assertSame('bon', $generation->account->slug);
        $this->assertSame(AiAccount::UNVERIFIED, $mauvais->refresh()->status);
    }

    /**
     * Un crédit épuisé n'est pas un débit trop rapide.
     *
     * Les fournisseurs rendent souvent le même statut pour les deux. Les
     * confondre fait réessayer indéfiniment chez un compte à sec — et envoie
     * régénérer une clé qui n'a rien de cassé.
     */
    public function test_an_exhausted_credit_takes_the_account_out_but_a_rate_limit_does_not(): void
    {
        $sec = $this->account('a-sec', priority: 10);
        $this->account('finance', priority: 20);

        FakeDriver::failOnce('AI_CREDIT_EXHAUSTED');

        $generation = $this->execute('prompt-fast', ['prompt' => 'Bonjour.']);

        $this->assertSame('finance', $generation->account->slug);
        $this->assertSame(AiAccount::UNVERIFIED, $sec->refresh()->status);
        $this->assertSame('quota_exhausted', $sec->verification_reason);

        // Le débit trop rapide, lui, ne retire rien : il se résout seul.
        FakeDriver::reset();
        $charge = $this->account('charge', priority: 5);
        FakeDriver::failOnce('AI_RATE_LIMITED');

        $this->execute('prompt-fast', ['prompt' => 'Bonjour.']);

        $this->assertSame(AiAccount::ACTIVE, $charge->refresh()->status);
    }

    /**
     * La chaîne descend au repli **après** avoir épuisé les comptes du modèle
     * préféré : changer de compte ne dégrade rien, changer de modèle si.
     */
    public function test_the_preferred_model_is_exhausted_before_the_fallback(): void
    {
        $this->account('a', priority: 10, models: ['gemini-2.5-flash', 'deepseek-chat']);
        $this->account('b', priority: 20, models: ['gemini-2.5-flash', 'deepseek-chat']);

        FakeDriver::failOnce('AI_RATE_LIMITED');

        $this->execute('prompt-fast', ['prompt' => 'Bonjour.']);

        // `prompt-fast` : gemini-2.5-flash, repli deepseek-chat.
        $this->assertSame(['gemini-2.5-flash', 'gemini-2.5-flash'], FakeDriver::$seenModels);
    }

    /**
     * Réessayer est une **décision de coût**. La prendre à la place de
     * l'appelant lui ferait payer deux fois ce qu'il croit être un appel.
     */
    public function test_an_idempotency_key_returns_the_first_generation_even_if_it_failed(): void
    {
        $this->account('principal');

        FakeDriver::failOnce('AI_PROVIDER_TIMEOUT', 504);

        $premiere = $this->execute('prompt-fast', ['prompt' => 'Bonjour.'], key: 'k-1');
        $this->assertSame(AiGeneration::FAILED, $premiere->status);

        $seconde = $this->execute('prompt-fast', ['prompt' => 'Bonjour.'], key: 'k-1');

        $this->assertTrue($seconde->is($premiere));
        $this->assertSame(1, FakeDriver::$calls, 'Une clé déjà vue ne doit rien relancer.');
    }

    /**
     * Le registre de dépense n'additionne jamais nos comptes et ceux d'un
     * client : le quota ne porte que sur les nôtres, et les deux nombres n'ont
     * pas la même exactitude.
     */
    public function test_the_two_kinds_of_cost_stay_apart(): void
    {
        $this->account('plateforme');
        $this->execute('prompt-fast', ['prompt' => 'Bonjour.']);

        $this->account('a-lui', organizationId: $this->org);
        $this->execute('prompt-fast', ['prompt' => 'Bonjour.'], account: 'a-lui');

        $ligne = DB::table('ai_spend')->where('organization_id', $this->org)->first();

        $this->assertSame(2, (int) $ligne->generations);
        $this->assertGreaterThan(0, (int) $ligne->cost_micros);
        $this->assertGreaterThan(0, (int) $ligne->cost_micros_byo);
        $this->assertNotSame((int) $ligne->cost_micros + (int) $ligne->cost_micros_byo, (int) $ligne->cost_micros);
    }

    /**
     * Sur le compte d'un tiers, notre calcul suit les prix publics ; son tarif
     * négocié donne autre chose. Le dire est plus utile que de prétendre à
     * l'exactitude.
     */
    public function test_a_cost_on_someone_elses_key_is_marked_estimated(): void
    {
        $this->account('a-lui', organizationId: $this->org);

        $generation = $this->execute('prompt-fast', ['prompt' => 'Bonjour.'], account: 'a-lui');

        $this->assertTrue($generation->cost_estimated);
    }

    /**
     * **Le quota du plan ne porte que sur nos comptes.**
     *
     * Ce qu'un client dépense sur sa propre clé, il le paie à son fournisseur ;
     * le lui compter reviendrait à lui facturer deux fois la même chose — une
     * fois en crédits, une fois en dollars.
     */
    public function test_the_plan_quota_stops_our_accounts_and_not_the_clients(): void
    {
        $this->app->bind(BillingContract::class, fn (): BillingContract => new class implements BillingContract
        {
            public function limit(string $organizationId, string $key): PlanLimit
            {
                return PlanLimit::of(1);
            }
        });

        DB::table('ai_spend')->insert([
            'organization_id' => $this->org,
            'period' => now()->format('Y-m'),
            'cost_micros' => 5_000,
            'cost_micros_byo' => 0,
            'generations' => 1,
        ]);

        $this->account('plateforme');

        try {
            $this->execute('prompt-fast', ['prompt' => 'Bonjour.']);
            $this->fail('Les crédits sont épuisés : l\'appel doit être refusé.');
        } catch (DomainException $e) {
            $this->assertSame('AI_QUOTA_EXCEEDED', $e->errorCode);
        }

        // Sur sa propre clé, le même appel passe.
        $this->account('a-lui', organizationId: $this->org);

        $this->assertSame(
            AiGeneration::SUCCEEDED,
            $this->execute('prompt-fast', ['prompt' => 'Bonjour.'], account: 'a-lui')->status,
        );
    }

    /**
     * Le garde-fou contre l'emballement, indépendant de tout plan : sans lui,
     * une organisation au plan illimité n'aurait aucune borne.
     */
    public function test_the_absolute_cap_stops_everything(): void
    {
        config(['ai.spend_cap_micros' => 1]);

        $this->account('principal');

        DB::table('ai_spend')->insert([
            'organization_id' => (string) Str::uuid(),
            'period' => now()->format('Y-m'),
            'cost_micros' => 5_000,
            'cost_micros_byo' => 0,
            'generations' => 1,
        ]);

        try {
            $this->execute('prompt-fast', ['prompt' => 'Bonjour.']);
            $this->fail('Le plafond absolu doit arrêter l\'appel.');
        } catch (DomainException $e) {
            $this->assertSame('AI_SPEND_CAP_REACHED', $e->errorCode);
        }

        $this->assertSame(0, FakeDriver::$calls);
        $this->assertSame(0, AiGeneration::query()->count(), 'Rien ne doit être ouvert si rien ne partira.');
    }

    /**
     * Un fichier collé dans un champ de saisie doit être arrêté avant qu'on en
     * paie les jetons.
     */
    public function test_an_input_beyond_the_task_bounds_is_refused_before_the_call(): void
    {
        $this->account('principal');

        $this->expectExceptionMessage('32000');

        $this->execute('prompt-fast', ['prompt' => str_repeat('a', 200_000)]);
    }

    /**
     * Le catalogue dit quelles tâches **existent**, la clé dit lesquelles ce
     * produit-là peut demander.
     */
    public function test_a_key_cannot_run_a_task_outside_its_scope(): void
    {
        $this->account('principal');

        $this->expectExceptionMessage('prompt-deep');

        app(RunTask::class)->handle(new TaskRequest(
            task: 'prompt-deep',
            actor: AiActor::apiKey((string) Str::uuid(), ['summarize'], $this->org),
            inputs: ['prompt' => 'Bonjour.'],
        ));
    }

    /**
     * Une sortie hors schéma est un aléa d'échantillonnage, pas une panne :
     * elle se réessaie sur le **même** compte. Aller ailleurs paierait un second
     * fournisseur pour un défaut qui n'est pas le sien.
     */
    public function test_an_invalid_json_output_is_retried_once_on_the_same_account(): void
    {
        $this->account('principal', priority: 10, models: ['claude-haiku-4-5', 'deepseek-chat']);
        $this->account('secours', priority: 20, models: ['claude-haiku-4-5', 'deepseek-chat']);

        FakeDriver::$output = 'ceci n\'est pas du json';

        $generation = $this->execute('classify', ['input' => 'Bonjour.', 'labels' => ['a', 'b']]);

        $this->assertSame('AI_OUTPUT_INVALID', $generation->failure_code);
        $this->assertSame(2, FakeDriver::$calls, 'Un seul réessai, sur le même compte.');

        // Les jetons des deux appels sont comptés : les cacher reviendrait à
        // s'offrir les échecs.
        $this->assertSame(200, $generation->input_tokens);
        $this->assertGreaterThan(0, (int) $generation->cost_micros);
    }

    public function test_a_json_task_accepts_a_valid_object(): void
    {
        $this->account('principal', models: ['claude-haiku-4-5', 'deepseek-chat']);

        FakeDriver::$output = '{"label":"a"}';

        $this->assertSame(
            AiGeneration::SUCCEEDED,
            $this->execute('classify', ['input' => 'Bonjour.', 'labels' => ['a', 'b']])->status,
        );
        $this->assertSame(1, FakeDriver::$calls);
    }

    /**
     * @param  array<string, mixed>  $inputs
     */
    private function execute(string $task, array $inputs, ?string $key = null, ?string $account = null): AiGeneration
    {
        return app(RunTask::class)->handle(new TaskRequest(
            task: $task,
            actor: AiActor::user((string) Str::uuid(), $this->org),
            inputs: $inputs,
            idempotencyKey: $key,
            account: $account,
        ));
    }

    /**
     * @param  list<string>  $models
     */
    private function account(
        string $slug,
        int $priority = 100,
        ?string $organizationId = null,
        array $models = ['gemini-2.5-flash', 'deepseek-chat'],
    ): AiAccount {
        return AiAccount::query()->create([
            'slug' => $slug,
            'driver' => 'fake',
            'config' => ['base_url' => 'https://faux.exemple.cm'],
            'credentials' => ['api_key' => 'x'],
            'models' => $models,
            'owner_organization_id' => $organizationId,
            'environment' => app()->environment(),
            'status' => AiAccount::ACTIVE,
            'priority' => $priority,
            'verified_at' => now(),
        ]);
    }
}
