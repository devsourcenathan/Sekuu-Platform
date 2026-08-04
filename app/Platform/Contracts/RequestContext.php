<?php

declare(strict_types=1);

namespace App\Platform\Contracts;

/**
 * Qui appelle, pour la requête en cours.
 *
 * Existe pour qu'un module n'ait pas à connaître **l'infrastructure**
 * d'authentification d'un autre. Jusqu'ici, le trait de résolution
 * d'organisation de Billing importait `JwtUserResolver` — une classe
 * d'infrastructure d'Identity, pas un contrat. Une entorse discrète, qu'un
 * second module aurait dupliquée.
 *
 * Volontairement pauvre : trois questions, et rien qui expose le modèle
 * d'Identity.
 *
 * @see docs/01-overview/architecture.md
 */
interface RequestContext
{
    /**
     * `null` si le token ne porte aucune organisation active.
     */
    public function organizationId(): ?string;

    public function userId(): string;

    /**
     * @param  list<string>  $roles
     */
    public function hasAnyRole(array $roles): bool;
}
