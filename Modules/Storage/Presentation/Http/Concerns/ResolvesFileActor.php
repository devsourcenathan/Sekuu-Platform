<?php

declare(strict_types=1);

namespace Modules\Storage\Presentation\Http\Concerns;

use App\Platform\Contracts\FileActor;
use App\Platform\Contracts\RequestContext;
use Illuminate\Http\Request;
use Modules\Identity\Infrastructure\Auth\ApiKeyResolver;

/**
 * Deux formes d'appelant, et il en faut deux.
 *
 * Une clé d'API agit au nom d'un **produit**, pas d'une personne : il n'existe
 * aucun utilisateur Sekuu derrière un apprenant Learn. Lui inventer un
 * identifiant d'utilisateur ferait entrer un acteur fictif dans le journal des
 * accès, qui n'a qu'une raison d'être — dire la vérité.
 *
 * La clé porte ses propres bornes : la liste des `owner_type` qu'elle peut
 * manipuler, et son plafond de rétention. Elle **habilite**, elle n'hérite de
 * rien — les deux valent zéro à l'émission.
 *
 * L'authentification est faite ici plutôt que par un middleware de route,
 * exactement comme côté Payments : les deux schémas partagent l'en-tête
 * `Authorization`, et un garde qui n'en connaîtrait qu'un rejetterait l'autre
 * avant qu'il n'atteigne le contrôleur.
 *
 * @see docs/03-services/storage/07-external-api.md
 */
trait ResolvesFileActor
{
    /** Déposer, confirmer, supprimer. */
    protected const WRITE = 'storage.write';

    /** Lire les métadonnées, et obtenir une URL. */
    protected const READ = 'storage.read';

    /** Enregistrer et administrer ses propres magasins. */
    protected const DESTINATIONS = 'storage.destinations';

    protected function actor(Request $request, string $scope): FileActor
    {
        $key = app(ApiKeyResolver::class)->resolve($request);

        if ($key !== null) {
            // `require` relit la clé et vérifie le scope. Une clé habilitée à
            // déposer ne doit pas pouvoir enregistrer un magasin : ce sont deux
            // dangers différents, et un seul droit pour les deux serait le plus
            // large des deux.
            app(ApiKeyResolver::class)->require($request, $scope);

            return FileActor::apiKey(
                keyId: (string) $key->key->id,
                ownerTypes: $key->subjectTypes(),
                organizationId: $key->organizationId(),
                maxRetentionDays: $key->maxRetentionDays(),
            );
        }

        $context = app(RequestContext::class);

        return FileActor::user($context->userId(), $context->organizationId());
    }

    /**
     * L'organisation de l'appelant, quelle que soit sa forme.
     */
    protected function callerOrganizationId(Request $request, string $scope): ?string
    {
        return $this->actor($request, $scope)->organizationId;
    }
}
