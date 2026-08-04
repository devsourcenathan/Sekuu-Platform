<?php

declare(strict_types=1);

namespace Tests\Concerns;

/**
 * Inscription, création d'organisation, puis bascule du token dessus.
 *
 * Passe par l'API plutôt que par des factories : les autorisations dépendent de
 * rôles et d'un claim d'organisation dans le token, et les fabriquer à la main
 * laisserait le test vert sur un chemin que personne n'emprunte.
 *
 * Promu hors de Billing : tout module ayant des routes authentifiées en a
 * besoin, et une copie par module divergerait au premier changement du parcours
 * d'inscription.
 */
trait SignsInAsOwner
{
    protected string $ownerToken;

    protected string $organizationId;

    protected function signInAsOwner(
        string $email = 'nathan@sekuu.com',
        string $organizationName = 'SOS Clinique',
    ): void {
        $token = $this->postJson('/api/v1/auth/register', [
            'first_name' => 'Nathan',
            'last_name' => 'Tchinda',
            'email' => $email,
            'password' => 'un-mot-de-passe-long',
        ])->assertCreated()->json('data.access_token');

        $organization = $this->withToken($token)
            ->postJson('/api/v1/organizations', ['name' => $organizationName])
            ->assertCreated()
            ->json('data');

        $this->organizationId = $organization['id'];

        $this->flushHeaders();

        $this->ownerToken = $this->withToken($token)
            ->postJson('/api/v1/auth/switch-organization', ['organization_id' => $this->organizationId])
            ->assertOk()
            ->json('data.access_token');

        $this->flushHeaders();
    }
}
