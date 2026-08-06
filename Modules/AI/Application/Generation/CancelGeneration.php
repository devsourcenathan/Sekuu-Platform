<?php

declare(strict_types=1);

namespace Modules\AI\Application\Generation;

use App\Platform\Contracts\AiActor;
use App\Platform\Exceptions\DomainException;
use Modules\AI\Domain\Models\AiGeneration;

/**
 * Annuler, tant que rien n'est parti.
 *
 * ## Pourquoi seul `queued` s'annule
 *
 * Une fois la requête partie chez un fournisseur, il n'y a rien à annuler : les
 * jetons seront consommés et facturés, que la réponse nous intéresse encore ou
 * non.
 *
 * Prétendre le contraire — rendre `200` et jeter le résultat — donnerait
 * l'illusion d'économiser, et masquerait une dépense réelle. Le produit croirait
 * avoir arrêté quelque chose, et ne comprendrait pas sa facture.
 *
 * @see docs/03-services/ai/03-api.md
 */
final class CancelGeneration
{
    public function handle(string $generationId, AiActor $actor): AiGeneration
    {
        $generation = AiGeneration::query()->find($generationId);

        // Même erreur que « n'existe pas » : distinguer les deux dirait à qui
        // essaie des identifiants au hasard lesquels existent.
        if ($generation === null || $generation->organization_id !== $actor->organizationId) {
            throw DomainException::notFound('RESOURCE_NOT_FOUND', __('ai::messages.generation_not_found'));
        }

        if ($generation->status !== AiGeneration::QUEUED) {
            throw DomainException::conflict('AI_ALREADY_STARTED', __('ai::messages.already_started'));
        }

        $generation->forceFill([
            'status' => AiGeneration::CANCELLED,
            'completed_at' => now(),
        ])->save();

        /*
         * Le travail enfilé n'est pas retiré de la file : `RunTask::resume`
         * refuse tout ce qui n'est plus `queued`, et le travail s'éteindra de
         * lui-même. Chercher à le supprimer demanderait de connaître son
         * identifiant de file, ce qui lierait ce module à son pilote.
         */
        return $generation;
    }
}
