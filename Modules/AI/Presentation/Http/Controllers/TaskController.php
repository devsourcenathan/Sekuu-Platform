<?php

declare(strict_types=1);

namespace Modules\AI\Presentation\Http\Controllers;

use App\Platform\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\AI\Application\Generation\CancelGeneration;
use Modules\AI\Application\Generation\ReadGeneration;
use Modules\AI\Application\Generation\SubmitTask;
use Modules\AI\Application\Generation\TaskRequest;
use Modules\AI\Application\Models\ModelRegistry;
use Modules\AI\Application\Tasks\TaskDefinition;
use Modules\AI\Application\Tasks\TaskRegistry;
use Modules\AI\Domain\Models\AiGeneration;
use Modules\AI\Presentation\Http\Concerns\ResolvesAiActor;

/**
 * Le catalogue, la demande, l'issue, l'annulation.
 *
 * **Aucune de ces routes ne porte de champ `model`** — c'est l'invariant du
 * module, et le refuser au niveau de la validation est plus solide que de le
 * refuser à la lecture.
 *
 * @see docs/03-services/ai/03-api.md
 * @see docs/04-decisions/adr-0015-ai-task-not-model.md
 */
final class TaskController
{
    use ResolvesAiActor;

    public function __construct(
        private readonly TaskRegistry $tasks,
        private readonly ModelRegistry $models,
        private readonly SubmitTask $submit,
        private readonly ReadGeneration $reader,
        private readonly CancelGeneration $canceller,
    ) {}

    /**
     * Le catalogue.
     *
     * Sans cette route, un intégrateur devrait lire notre documentation pour
     * savoir ce qu'il peut demander, et découvrirait en production qu'une tâche
     * a changé de forme.
     *
     * Une clé ne voit **que ce qu'elle peut demander** : lui montrer le reste
     * l'inviterait à écrire du code contre une tâche qui lui rendra `403`.
     */
    public function index(Request $request): JsonResponse
    {
        $actor = $this->actor($request, self::READ);

        $catalogue = collect($this->tasks->all())
            ->filter(fn (TaskDefinition $task): bool => $actor->mayRun($task->name))
            ->map(fn (TaskDefinition $task): array => $this->describe($task))
            ->values()
            ->all();

        return ApiResponse::success($catalogue);
    }

    public function store(Request $request): JsonResponse
    {
        /*
         * `model`, `temperature`, `max_tokens`, `top_p` et `system` ne sont pas
         * simplement ignorés : ils sont **refusés**.
         *
         * Un champ ignoré en silence est un champ dont l'appelant croit qu'il
         * agit — et il en tirera des conclusions fausses sur ses résultats.
         */
        $validated = $request->validate([
            'task' => ['required', 'string', 'max:64'],
            'inputs' => ['required', 'array'],
            'history' => ['sometimes', 'array'],
            'history.*.role' => ['required_with:history', 'string', 'in:user,assistant'],
            'history.*.content' => ['required_with:history', 'string'],
            'account' => ['sometimes', 'nullable', 'string', 'max:64'],
            'model' => ['prohibited'],
            'temperature' => ['prohibited'],
            'max_tokens' => ['prohibited'],
            'top_p' => ['prohibited'],
            'system' => ['prohibited'],
        ]);

        $generation = $this->submit->handle(new TaskRequest(
            task: $validated['task'],
            actor: $this->actor($request, self::RUN),
            inputs: $validated['inputs'],
            history: $validated['history'] ?? [],

            // L'en-tête, pas le corps : une clé d'idempotence est une propriété
            // de la requête, pas de la demande.
            idempotencyKey: $request->header('Idempotency-Key'),

            account: $validated['account'] ?? null,
        ));

        /*
         * `202` pour une demande enfilée, `200` pour une tâche synchrone.
         *
         * Jamais `201` : ce qui est créé est une **demande**, pas un résultat —
         * la même distinction que côté paiement.
         */
        return $generation->status === AiGeneration::QUEUED
            ? ApiResponse::success($this->present($generation), status: 202)
            : ApiResponse::success($this->present($generation, $this->outputOf($generation, $request)));
    }

    public function show(Request $request, string $generationId): JsonResponse
    {
        $outcome = $this->reader->handle($generationId, $this->actor($request, self::READ));

        return ApiResponse::success($this->present($outcome['generation'], $outcome['output']));
    }

    public function cancel(Request $request, string $generationId): JsonResponse
    {
        $generation = $this->canceller->handle($generationId, $this->actor($request, self::RUN));

        return ApiResponse::success($this->present($generation));
    }

    /**
     * La sortie d'une tâche synchrone, lue tout de suite.
     *
     * C'est la seule fois où elle sera rendue : la lecture la consomme, et un
     * produit doit écrire ce qu'il reçoit.
     */
    private function outputOf(AiGeneration $generation, Request $request): ?string
    {
        if ($generation->status !== AiGeneration::SUCCEEDED) {
            return null;
        }

        return $this->reader->handle((string) $generation->id, $this->actor($request, self::RUN))['output'];
    }

    /**
     * @return array<string, mixed>
     */
    private function present(AiGeneration $generation, ?string $output = null): array
    {
        $body = [
            'id' => (string) $generation->id,
            'task' => $generation->task,
            'status' => $generation->status,
            'created_at' => $generation->created_at?->toIso8601String(),
            'completed_at' => $generation->completed_at?->toIso8601String(),
        ];

        if ($generation->status === AiGeneration::QUEUED || $generation->status === AiGeneration::RUNNING) {
            // Un ordre de grandeur, pas une promesse.
            $body['poll_after_ms'] = 1_500;

            return $body;
        }

        /*
         * `usage` est rendu **même en échec**, avec le coût réel. Un modèle qui
         * a produit une réponse hors schéma a consommé des jetons, et les cacher
         * reviendrait à s'offrir les échecs.
         */
        $body['usage'] = [
            'input_tokens' => $generation->input_tokens,
            'output_tokens' => $generation->output_tokens,
            'cost_micros' => $generation->cost_micros,
            'estimated' => (bool) $generation->cost_estimated,
        ];

        if ($generation->status === AiGeneration::FAILED) {
            // Le code, jamais le message brut du fournisseur : il peut porter un
            // identifiant d'organisation ou un nom de déploiement.
            $body['failure_code'] = $generation->failure_code;
        }

        if ($output !== null) {
            $body['output'] = $output;
        }

        return $body;
    }

    /**
     * @return array<string, mixed>
     */
    private function describe(TaskDefinition $task): array
    {
        $model = $this->models->get($task->model);

        return [
            'task' => $task->name,
            'inputs' => $task->inputs,
            'output' => $task->output,
            'synchronous' => $task->synchronous,
            'accepts_history' => $task->acceptsHistory,
            'retains_content' => $task->retainDays !== null,
            'max_input_tokens' => $task->maxInputTokens,
            'max_output_tokens' => $task->maxOutputTokens,

            /*
             * Un **ordre de grandeur**, pas un prix, et il n'engage à rien : le
             * coût réel dépend de l'entrée. Il permet à un produit de décider si
             * l'appel vaut la peine.
             *
             * Le modèle n'est pas rendu — seule la plateforme le nomme, et
             * l'exposer inviterait un produit à en dépendre.
             */
            'estimated_cost_micros' => $model->costMicros(
                (int) ($task->maxInputTokens / 10),
                (int) ($task->maxOutputTokens / 2),
            ),
        ];
    }
}
