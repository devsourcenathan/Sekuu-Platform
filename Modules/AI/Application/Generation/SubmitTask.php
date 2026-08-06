<?php

declare(strict_types=1);

namespace Modules\AI\Application\Generation;

use Modules\AI\Application\Tasks\TaskRegistry;
use Modules\AI\Domain\Models\AiGeneration;

/**
 * Le point d'entrée : synchrone ou enfilé, selon ce que la tâche déclare.
 *
 * ## Le synchrone est un confort, jamais une garantie
 *
 * Une tâche déclarée courte peut s'allonger — un modèle plus lent, un repli, un
 * fournisseur chargé — et le changement ne sera pas annoncé. Un appelant qui
 * suppose le synchrone casse ce jour-là.
 *
 * D'où cette classe : le choix est **déclaré par la tâche**, dans le dépôt, et
 * l'appelant ne peut ni le demander ni le forcer. Ce qu'il obtient est un `200`
 * avec la sortie, ou un `202` avec une demande à sonder, et il doit savoir
 * traiter les deux.
 *
 * @see docs/03-services/ai/03-api.md
 */
final class SubmitTask
{
    public function __construct(
        private readonly TaskRegistry $tasks,
        private readonly RunTask $runner,
    ) {}

    public function handle(TaskRequest $request): AiGeneration
    {
        return $this->tasks->get($request->task)->synchronous
            ? $this->runner->handle($request)
            : $this->runner->queue($request);
    }
}
