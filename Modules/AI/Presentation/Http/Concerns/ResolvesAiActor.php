<?php

declare(strict_types=1);

namespace Modules\AI\Presentation\Http\Concerns;

use App\Platform\Contracts\AiActor;
use App\Platform\Contracts\RequestContext;
use Illuminate\Http\Request;
use Modules\Identity\Infrastructure\Auth\ApiKeyResolver;

/**
 * Deux formes d'appelant, et une double borne pour la seconde.
 *
 * Une clé d'API agit au nom d'un **produit**, pas d'une personne. Elle porte
 * deux limites distinctes : le **scope** dit qu'elle peut exécuter des tâches,
 * la **liste blanche** dit lesquelles. Le catalogue dit ce qui existe ; une
 * tâche ajoutée n'habilite personne tant qu'aucune clé ne la porte.
 *
 * L'authentification est faite ici plutôt que par un middleware de route, comme
 * côté Payments et Storage : les deux schémas partagent l'en-tête
 * `Authorization`, et un garde qui n'en connaîtrait qu'un rejetterait l'autre
 * avant qu'il n'atteigne le contrôleur.
 *
 * @see docs/03-services/ai/07-external-api.md
 */
trait ResolvesAiActor
{
    /** Demander une exécution — donc **dépenser**. */
    protected const RUN = 'ai.run';

    /** Lire une issue, la consommation, le catalogue. */
    protected const READ = 'ai.read';

    /** Enregistrer et administrer ses propres comptes. */
    protected const ACCOUNTS = 'ai.accounts';

    protected function actor(Request $request, string $scope): AiActor
    {
        $key = app(ApiKeyResolver::class)->resolve($request);

        if ($key !== null) {
            // `require` relit la clé et vérifie le scope. Une clé habilitée à
            // exécuter ne doit pas pouvoir déposer un compte : ce sont deux
            // dangers différents.
            app(ApiKeyResolver::class)->require($request, $scope);

            return AiActor::apiKey(
                keyId: (string) $key->key->id,
                tasks: $key->aiTasks(),
                organizationId: $key->organizationId(),
            );
        }

        $context = app(RequestContext::class);

        return AiActor::user($context->userId(), $context->organizationId());
    }

    protected function callerOrganizationId(Request $request, string $scope): ?string
    {
        return $this->actor($request, $scope)->organizationId;
    }
}
