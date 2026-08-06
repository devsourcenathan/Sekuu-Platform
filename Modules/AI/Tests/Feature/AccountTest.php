<?php

declare(strict_types=1);

namespace Modules\AI\Tests\Feature;

use App\Platform\Events\DomainEvent;
use App\Platform\Exceptions\DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Modules\AI\Application\Accounts\RegisterAccount;
use Modules\AI\Application\Accounts\VerifyAccount;
use Modules\AI\Domain\Models\AiAccount;
use Modules\AI\Infrastructure\Drivers\FakeDriver;
use Tests\TestCase;

/**
 * Poser une clé, l'éprouver, la remplacer, la retirer.
 *
 * @see docs/03-services/ai/05-providers.md
 */
final class AccountTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        FakeDriver::reset();
    }

    public function test_a_registered_account_is_probed_immediately(): void
    {
        $compte = $this->register('plateforme');

        $this->assertSame(AiAccount::ACTIVE, $compte->status);
        $this->assertNotNull($compte->verified_at);
        $this->assertSame(1, FakeDriver::$calls, 'L\'enregistrement doit avoir généré, pas seulement écrit une ligne.');
    }

    /**
     * Une clé fausse ne se découvre pas au premier appel d'un client — et sur
     * une tâche synchrone, ce serait devant son utilisateur final.
     */
    public function test_a_rejected_key_leaves_the_account_out_of_service(): void
    {
        FakeDriver::failOnce('AI_CREDENTIALS_REJECTED');

        $compte = $this->register('refusee');

        $this->assertSame(AiAccount::UNVERIFIED, $compte->status);
        $this->assertSame(VerifyAccount::CREDENTIALS_REJECTED, $compte->verification_reason);
        $this->assertFalse($compte->canGenerate());
    }

    /**
     * **Le test qui compte de ce fichier.**
     *
     * Un `429` dit « pas maintenant », pas « votre clé est mauvaise ». Un compte
     * retiré du service pour cette raison le serait aux heures de charge —
     * c'est-à-dire précisément quand on en a besoin, et la reprise n'aurait lieu
     * que le lendemain.
     *
     * C'est la seule raison d'échec qui ne change pas l'état du compte.
     */
    public function test_a_rate_limited_account_keeps_serving(): void
    {
        Event::fake();

        $compte = $this->register('chargee');

        FakeDriver::failOnce('AI_RATE_LIMITED');
        $this->assertFalse(app(VerifyAccount::class)->handle($compte));

        $compte->refresh();
        $this->assertSame(AiAccount::ACTIVE, $compte->status, 'Un 429 ne doit pas retirer un compte du service.');

        // La raison est conservée : un compte durablement saturé est une
        // information d'exploitation, même s'il continue de servir.
        $this->assertSame(VerifyAccount::RATE_LIMITED, $compte->verification_reason);

        Event::assertNotDispatched(
            DomainEvent::class,
            fn (DomainEvent $e): bool => $e->type === 'ai.account.unverified',
        );
    }

    /**
     * Une clé révoquée chez le fournisseur doit se savoir avant qu'un client ne
     * le découvre.
     */
    public function test_an_account_that_stops_answering_says_so_once(): void
    {
        Event::fake();

        $compte = $this->register('revoquee');

        FakeDriver::failOnce('AI_CREDENTIALS_REJECTED');
        app(VerifyAccount::class)->handle($compte);

        $this->assertSame(AiAccount::UNVERIFIED, $compte->refresh()->status);

        Event::assertDispatched(
            DomainEvent::class,
            fn (DomainEvent $e): bool => $e->type === 'ai.account.unverified'
                && $e->get('slug') === 'revoquee'
                && $e->get('reason') === VerifyAccount::CREDENTIALS_REJECTED,
        );

        // Un compte déjà hors service qui échoue à nouveau n'est pas une
        // nouvelle : sans ce filtre, l'épreuve quotidienne produirait le même
        // événement chaque nuit jusqu'à la correction.
        Event::fake();
        FakeDriver::failOnce('AI_CREDENTIALS_REJECTED');
        app(VerifyAccount::class)->handle($compte->refresh());

        Event::assertNotDispatched(
            DomainEvent::class,
            fn (DomainEvent $e): bool => $e->type === 'ai.account.unverified',
        );
    }

    /**
     * `paused` et `disabled` sont des décisions humaines. L'épreuve constate,
     * elle ne les défait pas.
     */
    public function test_the_probe_does_not_undo_a_human_decision(): void
    {
        $compte = $this->register('rendue');
        $compte->forceFill(['status' => AiAccount::DISABLED])->save();

        $this->assertTrue(app(VerifyAccount::class)->handle($compte));
        $this->assertSame(AiAccount::DISABLED, $compte->refresh()->status, 'Une épreuve réussie ne rallume pas un compte rendu.');
    }

    /**
     * L'épreuve **est** la reprise : c'est ce qui rend supportable qu'un
     * amorçage par l'environnement échoue en silence.
     */
    public function test_a_corrected_account_comes_back_on_its_own(): void
    {
        FakeDriver::failOnce('AI_CREDENTIALS_REJECTED');
        $compte = $this->register('a-corriger');

        $this->assertSame(AiAccount::UNVERIFIED, $compte->status);

        $this->artisan('ai:verify')->assertSuccessful();

        $this->assertSame(AiAccount::ACTIVE, $compte->refresh()->status);
    }

    /**
     * Une clé de production sur un poste de développement facture de vrais
     * appels à chaque exécution de la suite, et personne ne le voit avant la
     * facture.
     */
    public function test_the_environment_guard_has_no_escape_hatch(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('production');

        $this->register('ailleurs', environment: 'production');
    }

    /**
     * Un préréglage complète, il ne contraint pas : c'est ce qui permet à un
     * client de pointer un serveur compatible que nous n'avons jamais vu.
     */
    public function test_a_preset_fills_in_and_the_caller_wins(): void
    {
        // Le premier compte part chez un vrai pilote : l'épreuve appelle, et
        // on ne laisse jamais une suite de tests sortir sur le réseau.
        Http::fake(['*' => Http::response([
            'choices' => [['message' => ['content' => '.']]],
            'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1],
        ])]);

        $compte = app(RegisterAccount::class)->handle(
            slug: 'deepseek-defaut',
            preset: 'deepseek',
            driver: null,
            config: [],
            credentials: ['api_key' => 'x'],
            models: ['deepseek-chat'],
            environment: app()->environment(),
        );

        $this->assertSame('openai', $compte->driver);
        $this->assertSame('https://api.deepseek.com/v1', $compte->baseUrl());

        $sien = app(RegisterAccount::class)->handle(
            slug: 'deepseek-a-lui',
            preset: 'deepseek',
            driver: 'fake',
            config: ['base_url' => 'https://ia.exemple.cm/v1'],
            credentials: ['api_key' => 'x'],
            models: ['fake-model'],
            environment: app()->environment(),
        );

        $this->assertSame('https://ia.exemple.cm/v1', $sien->baseUrl());
    }

    public function test_a_preset_that_requires_a_field_says_which(): void
    {
        $this->expectExceptionMessage('base_url');

        app(RegisterAccount::class)->handle(
            slug: 'vllm-sans-url',
            preset: 'vllm',
            driver: null,
            config: [],
            credentials: [],
            models: [],
            environment: app()->environment(),
        );
    }

    /**
     * Une rotation ratée ne doit pas mettre hors service un compte qui
     * fonctionnait : l'épreuve porte sur la nouvelle clé **avant** que
     * l'ancienne soit abandonnée.
     */
    public function test_a_failed_rotation_keeps_the_key_that_worked(): void
    {
        $compte = $this->register('en-rotation');

        FakeDriver::failOnce('AI_CREDENTIALS_REJECTED');

        try {
            app(RegisterAccount::class)->rotate($compte, ['api_key' => 'nouvelle-mauvaise']);
            $this->fail('Une rotation refusée doit lever.');
        } catch (DomainException $e) {
            $this->assertSame('AI_ACCOUNT_UNVERIFIED', $e->errorCode);
        }

        $compte->refresh();
        $this->assertSame(AiAccount::ACTIVE, $compte->status);
        $this->assertSame('clé-témoin', $compte->apiKey());
    }

    public function test_a_successful_rotation_replaces_the_key(): void
    {
        $compte = $this->register('rotation-ok');

        app(RegisterAccount::class)->rotate($compte, ['api_key' => 'nouvelle-bonne']);

        $this->assertSame('nouvelle-bonne', $compte->refresh()->apiKey());
    }

    /**
     * L'environnement **amorce**, et répare ce qui n'a jamais servi.
     *
     * Sans cette porte, une première tentative ratée serait définitive là où il
     * n'y a pas de shell : la ligne existerait, l'amorçage passerait son chemin,
     * et corriger une variable dans le tableau de bord n'aurait aucun effet.
     * C'est une impasse, et elle a été rencontrée sur Storage.
     */
    public function test_the_environment_repairs_an_account_that_never_served(): void
    {
        FakeDriver::failOnce('AI_CREDENTIALS_REJECTED');
        $compte = $this->register('a-reprendre');
        $this->assertSame(AiAccount::UNVERIFIED, $compte->status);

        $repare = app(RegisterAccount::class)->repair(
            account: $compte,
            preset: null,
            driver: 'fake',
            config: ['base_url' => 'https://corrige.exemple.cm'],
            credentials: ['api_key' => 'clé-corrigée'],
            models: ['fake-model'],
        );

        $this->assertSame(AiAccount::ACTIVE, $repare->status);
        $this->assertSame('clé-corrigée', $repare->apiKey());
    }

    /**
     * **Et il ne touche jamais à un compte qui fonctionne.**
     *
     * Une variable oubliée repointerait un compte en service vers une autre clé,
     * et les générations partiraient chez un fournisseur que personne n'a
     * choisi, facturées à quelqu'un d'autre.
     */
    public function test_the_environment_never_rewrites_an_account_that_works(): void
    {
        $compte = $this->register('en-service');

        app(RegisterAccount::class)->repair(
            account: $compte,
            preset: null,
            driver: 'fake',
            config: ['base_url' => 'https://autre.exemple.cm'],
            credentials: ['api_key' => 'clé-intruse'],
            models: ['fake-model'],
        );

        $this->assertSame('clé-témoin', $compte->refresh()->apiKey());
    }

    private function register(string $slug, ?string $environment = null): AiAccount
    {
        return app(RegisterAccount::class)->handle(
            slug: $slug,
            preset: null,
            driver: 'fake',
            config: ['base_url' => 'https://faux.exemple.cm'],
            credentials: ['api_key' => 'clé-témoin'],
            models: ['fake-model'],
            environment: $environment ?? app()->environment(),
        );
    }
}
