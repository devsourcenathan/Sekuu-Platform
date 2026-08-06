<?php

declare(strict_types=1);

namespace Modules\AI\Application\Generation;

use App\Platform\Contracts\AiActor;

/**
 * Ce qu'un appelant demande.
 *
 * ## Ce qu'on ne trouve pas ici
 *
 * Pas de `model`, pas de `temperature`, pas de `system`. C'est l'invariant du
 * module : **seule la plateforme nomme le modèle**, et le refuser au niveau du
 * type d'entrée est plus solide que de le refuser à la validation — un champ
 * qui n'existe pas ne se rajoute pas par distraction.
 *
 * @see docs/04-decisions/adr-0015-ai-task-not-model.md
 */
final readonly class TaskRequest
{
    /**
     * @param  array<string, mixed>  $inputs  Entrées propres à la tâche
     * @param  list<array{role: string, content: string}>  $history  Fil fourni par l'appelant ; le module n'en garde aucun
     * @param  string|null  $account  Compte nommé — « utilise ma clé »
     */
    public function __construct(
        public string $task,
        public AiActor $actor,
        public array $inputs,
        public array $history = [],
        public ?string $idempotencyKey = null,
        public ?string $account = null,
    ) {}
}
