<?php

declare(strict_types=1);

namespace Modules\AI\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\AI\Application\Models\ModelDefinition;
use Modules\AI\Application\Models\ModelRegistry;
use Tests\TestCase;

/**
 * La vue de l'exploitant sur le catalogue.
 *
 * C'est ici que se paie la dette assumée par l'ADR-0015 : quand un fournisseur
 * annonce un retrait, il faut savoir **quelles tâches nomment le modèle** et
 * **combien de générations sont encore parties dessus** avant de le retirer.
 *
 * @see docs/04-decisions/adr-0015-ai-task-not-model.md
 */
final class ModelsCommandTest extends TestCase
{
    use RefreshDatabase;

    /**
     * **Le repli est affiché, et signalé comme tel.**
     *
     * L'oublier serait l'erreur intéressante : c'est le chemin le moins
     * visible, celui qui ne sert que quand le premier modèle est déjà tombé —
     * et donc celui qu'on retirerait sans s'en apercevoir.
     */
    public function test_the_listing_names_the_tasks_including_their_fallbacks(): void
    {
        $this->artisan('ai:models')
            ->expectsOutputToContain('claude-haiku-4-5')

            // `deepseek-chat` n'est le modèle préféré d'aucune tâche ; il est le
            // repli de quatre.
            ->expectsOutputToContain('summarize (repli)')
            ->assertSuccessful();
    }

    /**
     * Le filtre existe pour une raison précise : sur un catalogue de trente
     * modèles, ce qui doit partir se noie dans ce qui va bien.
     */
    public function test_the_deprecated_filter_shows_only_what_has_to_go(): void
    {
        $this->artisan('ai:models', ['--deprecated' => true])
            ->expectsOutputToContain('Aucun modèle déprécié ou retiré.')
            ->assertSuccessful();

        app(ModelRegistry::class)->register(new ModelDefinition(
            id: 'claude-haiku-4-5',
            family: 'anthropic',
            context: 200_000,
            capabilities: ['json', 'tools', 'vision'],
            priceIn: 1.0,
            priceOut: 5.0,
            status: ModelDefinition::DEPRECATED,
        ));

        $this->artisan('ai:models', ['--deprecated' => true])
            ->expectsOutputToContain('claude-haiku-4-5')
            ->doesntExpectOutputToContain('deepseek-chat')
            ->assertSuccessful();
    }

    /**
     * Un modèle sans prix public affiche ce qu'il en est, jamais zéro : un
     * tableau de coûts qui affiche zéro pour une machine qu'on loue à l'heure
     * est un tableau qui ment.
     */
    public function test_a_model_without_a_public_price_says_so(): void
    {
        $this->artisan('ai:models')
            ->expectsOutputToContain('dépend de l\'hébergeur')
            ->assertSuccessful();
    }
}
