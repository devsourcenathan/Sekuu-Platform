<?php

declare(strict_types=1);

namespace Modules\AI\Tests\Feature;

use Modules\AI\Application\Models\ModelDefinition;
use Modules\AI\Application\Models\ModelRegistry;
use Modules\AI\Application\Tasks\TaskRegistry;
use Modules\AI\Infrastructure\Drivers\DriverRegistry;
use Tests\TestCase;

/**
 * Le catalogue : ce que la plateforme promet de savoir faire.
 *
 * @see docs/04-decisions/adr-0015-ai-task-not-model.md
 */
final class CatalogueTest extends TestCase
{
    /**
     * **Le test qui compte.**
     *
     * Un repli sans `json` sur une tâche qui en exige produirait une sortie
     * invalide sur le chemin le moins testé — celui qui ne sert que quand le
     * premier modèle est déjà tombé. C'est le genre de défaut qui ne se voit
     * qu'un mauvais jour, et cumulé avec un autre.
     */
    public function test_every_model_of_a_chain_satisfies_its_task(): void
    {
        $issues = app(TaskRegistry::class)->inconsistencies();

        $this->assertSame([], $issues, implode("\n", $issues));
    }

    /**
     * Une tâche ne peut pas nommer un modèle que personne n'a tarifé : ce
     * serait une facture qu'on ne saurait pas imputer.
     */
    public function test_every_model_belongs_to_a_registered_driver(): void
    {
        $drivers = app(DriverRegistry::class)->names();

        foreach (app(ModelRegistry::class)->all() as $model) {
            $this->assertContains(
                $model->family,
                $drivers,
                "Le modèle « {$model->id} » déclare le pilote « {$model->family} », qui n'existe pas.",
            );
        }
    }

    /**
     * L'invariant du module, vérifié sur la déclaration elle-même : aucune
     * tâche ne laisse l'appelant nommer un modèle.
     */
    public function test_no_task_accepts_a_model_as_an_input(): void
    {
        foreach (app(TaskRegistry::class)->all() as $task) {
            foreach (array_keys($task->inputs) as $field) {
                $this->assertNotContains(
                    $field,
                    ['model', 'temperature', 'max_tokens', 'top_p', 'system'],
                    "La tâche « {$task->name} » accepte « {$field} », ce que l'ADR-0015 refuse.",
                );
            }
        }
    }

    /**
     * `null` dit « on ne sait pas », zéro dirait « gratuit ». Un tableau de
     * coûts qui affiche zéro pour une machine qu'on loue à l'heure est un
     * tableau qui ment.
     */
    public function test_a_model_without_public_price_costs_null_not_zero(): void
    {
        $local = app(ModelRegistry::class)->get('llama-3.3-70b');

        $this->assertFalse($local->hasPublicPrice());
        $this->assertNull($local->costMicros(1_000, 500));
    }

    public function test_the_cost_follows_the_registry(): void
    {
        $model = app(ModelRegistry::class)->get('claude-sonnet-4-6');

        // 1 000 jetons à 3 $/M + 500 à 15 $/M = 0,0105 $ = 10 500 millionièmes.
        $this->assertSame(10_500, $model->costMicros(1_000, 500));
    }

    /**
     * Le cycle de vie : un modèle retiré sort de la chaîne, et la tâche passe à
     * son repli sans que personne ne change une ligne.
     */
    public function test_a_retired_model_leaves_the_chain(): void
    {
        $models = app(ModelRegistry::class);
        $models->register(new ModelDefinition(
            id: 'claude-haiku-4-5',
            family: 'anthropic',
            context: 200_000,
            capabilities: ['json', 'tools'],
            priceIn: 1.0,
            priceOut: 5.0,
            status: ModelDefinition::RETIRED,
        ));

        $tasks = app(TaskRegistry::class);
        $chain = $tasks->modelsFor($tasks->get('summarize'));

        $this->assertCount(1, $chain);
        $this->assertSame('deepseek-chat', $chain[0]->id);
    }

    /**
     * Les tâches libres existent, et ne contredisent pas l'invariant : ce qui
     * est refusé est que l'appelant nomme le modèle, pas qu'il écrive
     * librement.
     */
    public function test_free_form_tasks_exist_and_are_bounded(): void
    {
        $tasks = app(TaskRegistry::class);

        foreach (['prompt', 'prompt-fast', 'prompt-deep'] as $name) {
            $task = $tasks->get($name);

            $this->assertTrue($task->isFreeForm(), "« {$name} » devrait être libre.");

            // Ce sont les bornes qui tiennent le coût, à défaut d'un schéma.
            $this->assertGreaterThan(0, $task->maxInputTokens);
            $this->assertGreaterThan(0, $task->maxOutputTokens);
        }
    }

    /**
     * Une extraction qui varie d'un appel à l'autre est inexploitable, et un
     * produit qui la rapprocherait de sa base attribuerait les écarts à ses
     * données.
     */
    public function test_structured_tasks_are_deterministic(): void
    {
        foreach (['extract', 'classify'] as $name) {
            $task = app(TaskRegistry::class)->get($name);

            $this->assertSame(0.0, $task->temperature, "« {$name} » doit être déterministe.");
            $this->assertTrue($task->producesJson());
        }
    }

    public function test_an_unknown_task_fails_hard(): void
    {
        $this->expectExceptionMessage('inexistante');

        app(TaskRegistry::class)->get('tache.inexistante');
    }
}
