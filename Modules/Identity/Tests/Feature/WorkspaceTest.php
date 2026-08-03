<?php

declare(strict_types=1);

namespace Modules\Identity\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Identity\Tests\Concerns\CreatesIdentityFixtures;
use Tests\TestCase;

/**
 * @see docs/03-services/identity/03-api.md
 */
final class WorkspaceTest extends TestCase
{
    use CreatesIdentityFixtures;
    use RefreshDatabase;

    public function test_an_owner_can_create_a_workspace(): void
    {
        ['token' => $token, 'organization_id' => $organizationId] = $this->registerOwner();

        $this->withToken($token)
            ->postJson('/api/v1/workspaces', ['name' => 'Douala'])
            ->assertCreated()
            ->assertJsonPath('data.slug', 'douala')
            ->assertJsonPath('data.organization_id', $organizationId);
    }

    public function test_the_creator_becomes_a_member_of_the_workspace(): void
    {
        ['token' => $token] = $this->registerOwner();

        $workspaceId = $this->createWorkspace($token, 'Douala');

        $this->withToken($token)->getJson("/api/v1/workspaces/{$workspaceId}/members")
            ->assertOk()
            ->assertJsonPath('data.0.user.email', 'nathan@sekuu.com');
    }

    public function test_a_token_without_active_organization_is_refused(): void
    {
        $token = $this->registerUser();

        $this->withToken($token)->getJson('/api/v1/workspaces')
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'ORGANIZATION_REQUIRED');
    }

    public function test_a_slug_cannot_be_reused_within_the_same_organization(): void
    {
        ['token' => $token] = $this->registerOwner();

        $this->createWorkspace($token, 'Douala');

        $this->withToken($token)->postJson('/api/v1/workspaces', ['name' => 'Douala'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'DUPLICATE_RESOURCE');
    }

    public function test_the_same_slug_is_free_in_another_organization(): void
    {
        ['token' => $first] = $this->registerOwner('nathan@sekuu.com', 'SOS Clinique');
        $this->createWorkspace($first, 'Douala');

        ['token' => $second] = $this->registerOwner('autre@sekuu.com', 'Autre entreprise');

        // L'unicité du slug est bornée à l'organisation, pas globale.
        $this->withToken($second)->postJson('/api/v1/workspaces', ['name' => 'Douala'])
            ->assertCreated();
    }

    /**
     * Le test central de l'isolation : le workspace d'une autre organisation
     * doit être indiscernable d'un workspace inexistant.
     */
    public function test_a_workspace_of_another_organization_is_invisible(): void
    {
        ['token' => $victim] = $this->registerOwner('nathan@sekuu.com', 'SOS Clinique');
        $workspaceId = $this->createWorkspace($victim, 'Douala');

        ['token' => $attacker] = $this->registerOwner('intrus@sekuu.com', 'Autre entreprise');

        $this->withToken($attacker)->getJson("/api/v1/workspaces/{$workspaceId}")
            ->assertNotFound()
            ->assertJsonPath('error.code', 'WORKSPACE_NOT_FOUND');

        $this->withToken($attacker)->patchJson("/api/v1/workspaces/{$workspaceId}", ['name' => 'Volé'])
            ->assertNotFound();

        $this->withToken($attacker)->deleteJson("/api/v1/workspaces/{$workspaceId}")
            ->assertNotFound();

        $this->withToken($attacker)->getJson("/api/v1/workspaces/{$workspaceId}/members")
            ->assertNotFound();
    }

    public function test_a_plain_member_only_sees_the_workspaces_they_belong_to(): void
    {
        ['token' => $ownerToken, 'organization_id' => $organizationId] = $this->registerOwner();

        $douala = $this->createWorkspace($ownerToken, 'Douala');
        $this->createWorkspace($ownerToken, 'Yaoundé');

        $memberToken = $this->inviteAndAccept($ownerToken, $organizationId, 'membre@sekuu.com', 'member');

        // Le membre ne voit rien tant qu'il n'est rattaché à aucun workspace.
        $this->withToken($memberToken)->getJson('/api/v1/workspaces')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->withToken($ownerToken)->postJson("/api/v1/workspaces/{$douala}/members", [
            'membership_id' => $this->membershipId('membre@sekuu.com', $organizationId),
        ])->assertCreated();

        $this->withToken($memberToken)->getJson('/api/v1/workspaces')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $douala);
    }

    public function test_a_member_of_the_organization_cannot_open_a_workspace_they_do_not_belong_to(): void
    {
        ['token' => $ownerToken, 'organization_id' => $organizationId] = $this->registerOwner();

        $yaounde = $this->createWorkspace($ownerToken, 'Yaoundé');
        $memberToken = $this->inviteAndAccept($ownerToken, $organizationId, 'membre@sekuu.com', 'member');

        // Même organisation, donc 403 et non 404 : l'existence n'est pas un secret.
        $this->withToken($memberToken)->getJson("/api/v1/workspaces/{$yaounde}")
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'WORKSPACE_ACCESS_DENIED');
    }

    public function test_a_plain_member_cannot_create_or_manage_workspaces(): void
    {
        ['token' => $ownerToken, 'organization_id' => $organizationId] = $this->registerOwner();

        $workspaceId = $this->createWorkspace($ownerToken, 'Douala');
        $memberToken = $this->inviteAndAccept($ownerToken, $organizationId, 'membre@sekuu.com', 'member');

        $this->withToken($memberToken)->postJson('/api/v1/workspaces', ['name' => 'Bafoussam'])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'INSUFFICIENT_PERMISSIONS');

        $this->withToken($memberToken)->deleteJson("/api/v1/workspaces/{$workspaceId}")
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'INSUFFICIENT_PERMISSIONS');
    }

    public function test_a_member_of_another_organization_cannot_be_added(): void
    {
        ['token' => $token] = $this->registerOwner('nathan@sekuu.com', 'SOS Clinique');
        $workspaceId = $this->createWorkspace($token, 'Douala');

        ['token' => $other, 'organization_id' => $otherOrganizationId] = $this->registerOwner('autre@sekuu.com', 'Autre entreprise');
        $foreignMembershipId = $this->membershipId('autre@sekuu.com', $otherOrganizationId);

        $this->withToken($token)->postJson("/api/v1/workspaces/{$workspaceId}/members", [
            'membership_id' => $foreignMembershipId,
        ])
            ->assertNotFound()
            ->assertJsonPath('error.code', 'MEMBERSHIP_NOT_FOUND');
    }

    public function test_a_member_can_be_removed_from_a_workspace(): void
    {
        ['token' => $ownerToken, 'organization_id' => $organizationId] = $this->registerOwner();

        $workspaceId = $this->createWorkspace($ownerToken, 'Douala');
        $memberToken = $this->inviteAndAccept($ownerToken, $organizationId, 'membre@sekuu.com', 'member');
        $membershipId = $this->membershipId('membre@sekuu.com', $organizationId);

        $this->withToken($ownerToken)
            ->postJson("/api/v1/workspaces/{$workspaceId}/members", ['membership_id' => $membershipId])
            ->assertCreated();

        $this->withToken($ownerToken)
            ->deleteJson("/api/v1/workspaces/{$workspaceId}/members/{$membershipId}")
            ->assertNoContent();

        $this->withToken($memberToken)->getJson('/api/v1/workspaces')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_the_same_member_cannot_be_added_twice(): void
    {
        ['token' => $token, 'organization_id' => $organizationId] = $this->registerOwner();

        $workspaceId = $this->createWorkspace($token, 'Douala');
        $membershipId = $this->membershipId('nathan@sekuu.com', $organizationId);

        // Le créateur est déjà membre.
        $this->withToken($token)
            ->postJson("/api/v1/workspaces/{$workspaceId}/members", ['membership_id' => $membershipId])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'DUPLICATE_RESOURCE');
    }

    private function createWorkspace(string $token, string $name): string
    {
        return $this->withToken($token)
            ->postJson('/api/v1/workspaces', ['name' => $name])
            ->assertCreated()
            ->json('data.id');
    }

    private function inviteAndAccept(
        string $ownerToken,
        string $organizationId,
        string $email,
        string $role,
    ): string {
        $invitationToken = $this->withToken($ownerToken)
            ->postJson("/api/v1/organizations/{$organizationId}/invitations", [
                'email' => $email,
                'global_role_id' => $this->roleId($role),
            ])
            ->assertCreated()
            ->json('data.token');

        // withToken persiste entre les requêtes du test : sans ce flush, on
        // accepterait l'invitation avec le token du propriétaire.
        $this->flushHeaders();

        $this->postJson("/api/v1/invitations/{$invitationToken}/accept", [
            'first_name' => 'Membre',
            'last_name' => 'Test',
            'password' => 'un-mot-de-passe-long',
        ])->assertOk();

        $token = $this->postJson('/api/v1/auth/login', [
            'email' => $email,
            'password' => 'un-mot-de-passe-long',
        ])->assertOk()->json('data.access_token');

        return $this->switchTo($token, $organizationId);
    }
}
