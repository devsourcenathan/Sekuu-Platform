<?php

declare(strict_types=1);

namespace Modules\AI\Application\Tasks;

use App\Platform\Exceptions\DomainException;
use Modules\AI\Application\Models\ModelDefinition;
use Modules\AI\Application\Models\ModelRegistry;

/**
 * Le catalogue des tâches, et le seul vocabulaire qu'un appelant connaisse.
 *
 * @see docs/04-decisions/adr-0015-ai-task-not-model.md
 */
final class TaskRegistry
{
    /** @var array<string, TaskDefinition> */
    private array $tasks = [];

    public function __construct(private readonly ModelRegistry $models) {}

    public function register(TaskDefinition $task): void
    {
        $this->tasks[$task->name] = $task;
    }

    /**
     * Une tâche inconnue échoue **durement**.
     *
     * Pas de repli vers un modèle générique : ce serait rouvrir la porte que
     * l'ADR-0015 ferme, avec en prime une facture imprévisible.
     */
    public function get(string $name): TaskDefinition
    {
        return $this->tasks[$name] ?? throw DomainException::unprocessable(
            'AI_TASK_UNKNOWN',
            __('ai::messages.task_unknown', ['task' => $name]),
        );
    }

    public function knows(string $name): bool
    {
        return isset($this->tasks[$name]);
    }

    /**
     * @return array<string, TaskDefinition>
     */
    public function all(): array
    {
        return $this->tasks;
    }

    /**
     * Les modèles d'une tâche, dans l'ordre d'essai, **retirés exclus**.
     *
     * C'est ici que se paie la dette assumée par l'ADR-0015 : quand un
     * fournisseur annonce un retrait, on marque le modèle `retired`, la tâche
     * passe à son repli, et aucun produit n'a rien à changer.
     *
     * @return list<ModelDefinition>
     */
    public function modelsFor(TaskDefinition $task): array
    {
        $chain = [];

        foreach ($task->chain() as $id) {
            $model = $this->models->get($id);

            if (! $model->isRetired()) {
                $chain[] = $model;
            }
        }

        return $chain;
    }

    /**
     * Chaque modèle d'une chaîne satisfait-il les exigences de sa tâche ?
     *
     * Vérifié par un test, jamais à l'exécution : un repli sans `json` sur une
     * tâche qui en exige produirait une sortie invalide **sur le chemin le
     * moins testé** — celui qui ne sert que quand le premier modèle est déjà
     * tombé. C'est le genre de défaut qui ne se voit qu'un mauvais jour, et
     * cumulé avec un autre.
     *
     * @return list<string> Les incohérences, vide si tout va bien
     */
    public function inconsistencies(): array
    {
        $issues = [];

        foreach ($this->tasks as $task) {
            foreach ($task->chain() as $id) {
                if (! $this->models->knows($id)) {
                    $issues[] = "{$task->name} : modèle « {$id} » absent du registre";

                    continue;
                }

                $model = $this->models->get($id);

                if (! $model->satisfies($task->requires)) {
                    $missing = implode(', ', array_diff($task->requires, $model->capabilities));
                    $issues[] = "{$task->name} : « {$id} » ne sait pas {$missing}";
                }

                if ($model->context < $task->maxInputTokens) {
                    $issues[] = "{$task->name} : « {$id} » n'accepte que {$model->context} jetons";
                }
            }
        }

        return $issues;
    }
}
