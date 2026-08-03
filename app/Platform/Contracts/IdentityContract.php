<?php

declare(strict_types=1);

namespace App\Platform\Contracts;

/**
 * Lecture synchrone d'Identity par les autres modules.
 *
 * C'est le premier contrat de la couche partagée décrite par
 * [l'architecture § 11.1](docs/01-overview/architecture.md) : un module appelle
 * un autre à travers une **interface publique**, jamais son modèle Eloquent ni
 * sa table.
 *
 * Le jour où Identity est extrait, seule l'implémentation change — l'appel
 * local devient un appel HTTP, et les appelants ne sont pas modifiés.
 *
 * Un appel synchrone n'est autorisé que pour une **lecture**. Tout effet de
 * bord passe par un événement.
 */
interface IdentityContract
{
    /**
     * Personne à prévenir au sujet de la facturation d'une organisation.
     *
     * `null` si l'organisation n'existe plus, ou n'a plus aucun membre
     * joignable. L'appelant doit traiter ce cas : une organisation sans contact
     * ne reçoit rien, et cela doit se voir plutôt que d'échouer en silence.
     */
    public function billingContact(string $organizationId): ?BillingContact;
}
