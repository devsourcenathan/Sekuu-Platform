<?php

declare(strict_types=1);

namespace Modules\AI\Application\Generation;

use App\Platform\Contracts\AiActor;
use App\Platform\Exceptions\DomainException;
use Modules\AI\Application\Tasks\TaskRegistry;
use Modules\AI\Domain\Models\AiContent;
use Modules\AI\Domain\Models\AiGeneration;

/**
 * Lire l'issue d'une génération, et sa sortie **une fois**.
 *
 * ## Pourquoi une seule fois
 *
 * La sortie n'est pas conservée : la garder ferait de cette base le dépôt de ce
 * que tous les produits ont de plus sensible, et la ferait doubler tous les
 * mois. Elle vit assez pour qu'un sondage la trouve, et disparaît quand elle a
 * été remise.
 *
 * Un produit doit donc écrire ce qu'il reçoit — et c'est le comportement qu'on
 * veut, puisqu'il est le seul à savoir où cela a sa place.
 *
 * La relecture n'échoue pas pour autant : elle rend les métriques, sans la
 * sortie. Une erreur ferait croire à une génération perdue alors qu'elle a bien
 * eu lieu, et a été payée.
 *
 * ## La tâche peut déclarer une rétention
 *
 * Dans ce cas la sortie reste lisible jusqu'à l'expiration. C'est un choix
 * inscrit dans le dépôt, tâche par tâche, jamais un défaut.
 *
 * @see docs/04-decisions/adr-0016-ai-spend-and-privacy.md
 */
final class ReadGeneration
{
    public function __construct(private readonly TaskRegistry $tasks) {}

    /**
     * @return array{generation: AiGeneration, output: string|null}
     */
    public function handle(string $generationId, AiActor $actor): array
    {
        $generation = AiGeneration::query()->find($generationId);

        /*
         * « Pas la vôtre » et « n'existe pas » rendent la **même** erreur.
         *
         * Distinguer les deux dirait à qui essaie des identifiants au hasard
         * lesquels existent — et l'identifiant d'une génération suffit à savoir
         * qu'une organisation en a demandé une.
         */
        if ($generation === null || $generation->organization_id !== $actor->organizationId) {
            throw DomainException::notFound('RESOURCE_NOT_FOUND', __('ai::messages.generation_not_found'));
        }

        if ($generation->status !== AiGeneration::SUCCEEDED) {
            return ['generation' => $generation, 'output' => null];
        }

        return ['generation' => $generation, 'output' => $this->consume($generation)];
    }

    private function consume(AiGeneration $generation): ?string
    {
        $content = AiContent::query()->find($generation->id);

        if ($content === null || $content->expires_at->isPast()) {
            return null;
        }

        $output = $content->output;

        // Une tâche qui déclare une rétention garde sa sortie jusqu'au terme
        // annoncé : la lecture ne la consomme pas.
        if ($this->tasks->get((string) $generation->task)->retainDays === null) {
            $content->delete();
        }

        return $output;
    }
}
