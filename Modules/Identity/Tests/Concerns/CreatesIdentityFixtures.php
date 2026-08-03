<?php

declare(strict_types=1);

namespace Modules\Identity\Tests\Concerns;

use Modules\Identity\Domain\Models\GlobalRole;
use Modules\Identity\Domain\Models\Membership;

/**
 * Raccourcis de mise en situation, communs aux tests du module.
 */
trait CreatesIdentityFixtures
{
    /**
     * Inscrit un utilisateur et renvoie son access token, sans organisation.
     */
    protected function registerUser(string $email = 'nathan@sekuu.com'): string
    {
        return $this->postJson('/api/v1/auth/register', [
            'first_name' => 'Nathan',
            'last_name' => 'Tchinda',
            'email' => $email,
            'password' => 'un-mot-de-passe-long',
        ])->assertCreated()->json('data.access_token');
    }

    /**
     * Inscrit un utilisateur, lui crée une organisation, et renvoie un token
     * portant cette organisation — donc les scopes du rôle owner.
     *
     * @return array{token: string, organization_id: string}
     */
    protected function registerOwner(
        string $email = 'nathan@sekuu.com',
        string $organizationName = 'SOS Clinique',
    ): array {
        $token = $this->registerUser($email);

        $organizationId = $this->withToken($token)
            ->postJson('/api/v1/organizations', ['name' => $organizationName])
            ->assertCreated()
            ->json('data.id');

        return [
            'token' => $this->switchTo($token, $organizationId),
            'organization_id' => $organizationId,
        ];
    }

    protected function switchTo(string $token, string $organizationId): string
    {
        return $this->withToken($token)
            ->postJson('/api/v1/auth/switch-organization', ['organization_id' => $organizationId])
            ->assertOk()
            ->json('data.access_token');
    }

    protected function roleId(string $slug): string
    {
        return GlobalRole::query()->where('slug', $slug)->firstOrFail()->id;
    }

    protected function membershipId(string $email, string $organizationId): string
    {
        return Membership::query()
            ->where('organization_id', $organizationId)
            ->whereHas('user', fn ($q) => $q->where('email', $email))
            ->firstOrFail()
            ->id;
    }
}
