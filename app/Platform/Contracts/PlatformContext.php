<?php

declare(strict_types=1);

namespace App\Platform\Contracts;

/**
 * Qui administre la plateforme, pour la requête en cours.
 *
 * Distinct de {@see RequestContext}, qui répond « au nom de quelle organisation
 * agit cet appelant ». Ici la réponse est **au nom de Sekuu**, ce qu'aucun rôle
 * d'organisation ne permet.
 *
 * Volontairement pauvre : une question, et rien qui expose le modèle d'Identity.
 *
 * @see docs/04-decisions/adr-0018-platform-operator.md
 */
interface PlatformContext
{
    /**
     * Cet appelant a-t-il cette permission de plateforme ?
     *
     * Rend `false` pour tout le monde en l'absence d'habilitation — y compris
     * pour un `owner`, y compris pour un appelant non authentifié.
     */
    public function may(string $permission): bool;

    /**
     * L'identifiant de l'opérateur, pour le journal d'audit. `null` s'il n'y en
     * a pas.
     */
    public function operatorId(): ?string;
}
