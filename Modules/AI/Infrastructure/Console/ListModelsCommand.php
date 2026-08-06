<?php

declare(strict_types=1);

namespace Modules\AI\Infrastructure\Console;

use Illuminate\Console\Command;
use Modules\AI\Application\Models\ModelDefinition;
use Modules\AI\Application\Models\ModelRegistry;
use Modules\AI\Application\Tasks\TaskDefinition;
use Modules\AI\Application\Tasks\TaskRegistry;
use Modules\AI\Domain\Models\AiGeneration;

/**
 * Le catalogue vu de l'exploitant : qui coûte quoi, qui sert encore, qui part.
 *
 * ## À quoi elle sert vraiment
 *
 * C'est ici que se paie la dette assumée par l'ADR-0015. Quand un fournisseur
 * annonce un retrait, on marque le modèle `deprecated` — et il faut alors
 * pouvoir répondre à deux questions avant de le passer `retired` : **quelles
 * tâches le nomment**, et **combien de générations sont encore parties dessus ce
 * mois-ci**.
 *
 * Sans cette vue, la réponse se cherche dans les journaux, et on finit par
 * retirer le modèle en espérant.
 *
 * @see docs/04-decisions/adr-0015-ai-task-not-model.md
 */
final class ListModelsCommand extends Command
{
    protected $signature = 'ai:models {--deprecated : N affiche que ce qui doit partir}';

    protected $description = 'Le registre des modèles : prix, capacités, cycle de vie, usage réel.';

    public function handle(ModelRegistry $models, TaskRegistry $tasks): int
    {
        $rows = [];

        foreach ($models->all() as $model) {
            if ($this->option('deprecated') && $model->status === ModelDefinition::PREFERRED) {
                continue;
            }

            $rows[] = [
                $model->id,
                $model->family,
                $this->price($model),
                implode(', ', $model->capabilities),
                $this->lifecycle($model),
                implode(', ', $this->tasksNaming($tasks, $model->id)) ?: '—',
                (string) $this->generationsThisMonth($model->id),
            ];
        }

        if ($rows === []) {
            $this->info('Aucun modèle déprécié ou retiré.');

            return self::SUCCESS;
        }

        $this->table(
            ['Modèle', 'Pilote', 'Prix /M ($)', 'Capacités', 'Cycle', 'Tâches', 'Ce mois'],
            $rows,
        );

        $issues = $tasks->inconsistencies();

        if ($issues !== []) {
            /*
             * Normalement impossible : un test d'architecture vérifie la même
             * chose. Le redire ici couvre le cas où la configuration a été
             * modifiée sur le serveur sans repasser par la suite de tests.
             */
            $this->newLine();
            $this->error('Chaînes incohérentes :');

            foreach ($issues as $issue) {
                $this->line('  '.$issue);
            }

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * `null` dit « on ne sait pas », zéro dirait « gratuit ».
     *
     * Un tableau de coûts qui afficherait zéro pour une machine qu'on loue à
     * l'heure est un tableau qui ment.
     */
    private function price(ModelDefinition $model): string
    {
        return $model->hasPublicPrice()
            ? sprintf('%.2f / %.2f', $model->priceIn, $model->priceOut)
            : 'dépend de l\'hébergeur';
    }

    private function lifecycle(ModelDefinition $model): string
    {
        return match ($model->status) {
            ModelDefinition::RETIRED => '<fg=red>retiré</>',
            ModelDefinition::DEPRECATED => '<fg=yellow>déprécié</>',
            default => 'préféré',
        };
    }

    /**
     * Les tâches qui nomment ce modèle, en préféré **ou en repli**.
     *
     * Oublier le repli serait l'erreur intéressante : c'est le chemin le moins
     * visible, celui qui ne sert que quand le premier modèle est déjà tombé.
     *
     * @return list<string>
     */
    private function tasksNaming(TaskRegistry $tasks, string $modelId): array
    {
        $naming = [];

        foreach ($tasks->all() as $task) {
            /** @var TaskDefinition $task */
            if (in_array($modelId, $task->chain(), true)) {
                $naming[] = $task->model === $modelId ? $task->name : $task->name.' (repli)';
            }
        }

        return $naming;
    }

    private function generationsThisMonth(string $modelId): int
    {
        return AiGeneration::query()
            ->where('model', $modelId)
            ->where('created_at', '>=', now()->startOfMonth())
            ->count();
    }
}
