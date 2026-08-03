<?php

declare(strict_types=1);

namespace Modules\Identity\Tests\Feature;

use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Identity\Domain\Models\Organization;
use Tests\TestCase;

/**
 * @see docs/02-standards/security.md
 */
final class OrganizationContextTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_token_without_organization_carries_no_context(): void
    {
        $token = $this->registerAndGetToken();

        $claims = $this->decode($token);

        // Un token sans `org` n'ouvre que les routes de profil : les claims de
        // contexte sont absents, pas vides.
        $this->assertArrayNotHasKey('org', $claims);
        $this->assertArrayNotHasKey('roles', $claims);
        $this->assertArrayNotHasKey('scopes', $claims);
    }

    public function test_creating_an_organization_makes_the_user_its_owner(): void
    {
        $token = $this->registerAndGetToken();

        $organizationId = $this->withToken($token)
            ->postJson('/api/v1/organizations', ['name' => 'SOS Clinique'])
            ->assertCreated()
            ->assertJsonPath('data.slug', 'sos-clinique')
            ->json('data.id');

        $this->withToken($token)->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.organizations.0.id', $organizationId)
            ->assertJsonPath('data.organizations.0.roles', ['owner']);
    }

    public function test_switching_organization_returns_a_contextualised_token(): void
    {
        $token = $this->registerAndGetToken();

        $organizationId = $this->withToken($token)
            ->postJson('/api/v1/organizations', ['name' => 'SOS Clinique'])
            ->assertCreated()
            ->json('data.id');

        $switched = $this->withToken($token)
            ->postJson('/api/v1/auth/switch-organization', ['organization_id' => $organizationId])
            ->assertOk()
            ->json('data.access_token');

        $claims = $this->decode($switched);

        $this->assertSame($organizationId, $claims['org']);
        $this->assertSame(['owner'], $claims['roles']);
        $this->assertContains('organization.manage', $claims['scopes']);

        $this->withToken($switched)->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.context.organization_id', $organizationId)
            ->assertJsonPath('data.context.roles', ['owner']);
    }

    public function test_the_token_never_carries_business_permissions(): void
    {
        $token = $this->contextualisedToken();

        $claims = $this->decode($token);

        // Les permissions métier appartiennent aux produits : le token ne
        // transporte que des scopes globaux.
        foreach ($claims['scopes'] as $scope) {
            $this->assertStringNotContainsString('patient.', $scope);
            $this->assertStringNotContainsString('dealer.', $scope);
        }
    }

    public function test_the_token_carries_no_personal_data(): void
    {
        $claims = $this->decode($this->contextualisedToken());

        // Un JWT est signé, pas chiffré : son contenu est lisible par quiconque
        // le possède.
        $encoded = json_encode($claims);

        $this->assertStringNotContainsString('nathan@sekuu.com', $encoded);
        $this->assertStringNotContainsString('Tchinda', $encoded);
    }

    public function test_a_user_cannot_switch_to_an_organization_they_do_not_belong_to(): void
    {
        $token = $this->registerAndGetToken();

        $other = Organization::create(['name' => 'Autre entreprise', 'slug' => 'autre-entreprise']);

        // 404 et non 403 : un 403 confirmerait l'existence de l'organisation.
        $this->withToken($token)
            ->postJson('/api/v1/auth/switch-organization', ['organization_id' => $other->id])
            ->assertNotFound()
            ->assertJsonPath('error.code', 'MEMBERSHIP_NOT_FOUND');
    }

    public function test_switching_to_an_unknown_organization_is_indistinguishable(): void
    {
        $token = $this->registerAndGetToken();

        $this->withToken($token)
            ->postJson('/api/v1/auth/switch-organization', ['organization_id' => (string) Str::uuid()])
            ->assertNotFound()
            ->assertJsonPath('error.code', 'MEMBERSHIP_NOT_FOUND');
    }

    public function test_an_organization_slug_cannot_be_taken_twice(): void
    {
        $token = $this->registerAndGetToken();

        $this->withToken($token)->postJson('/api/v1/organizations', ['name' => 'SOS Clinique'])
            ->assertCreated();

        $this->withToken($token)->postJson('/api/v1/organizations', ['name' => 'SOS Clinique'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'ORGANIZATION_SLUG_TAKEN');
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $token): array
    {
        // Décodage sans vérification : on inspecte le contenu, pas la validité.
        [, $payload] = explode('.', $token);

        return (array) json_decode(JWT::urlsafeB64Decode($payload), true);
    }

    private function registerAndGetToken(): string
    {
        return $this->postJson('/api/v1/auth/register', [
            'first_name' => 'Nathan',
            'last_name' => 'Tchinda',
            'email' => 'nathan@sekuu.com',
            'password' => 'un-mot-de-passe-long',
        ])->assertCreated()->json('data.access_token');
    }

    private function contextualisedToken(): string
    {
        $token = $this->registerAndGetToken();

        $organizationId = $this->withToken($token)
            ->postJson('/api/v1/organizations', ['name' => 'SOS Clinique'])
            ->json('data.id');

        return $this->withToken($token)
            ->postJson('/api/v1/auth/switch-organization', ['organization_id' => $organizationId])
            ->json('data.access_token');
    }
}
