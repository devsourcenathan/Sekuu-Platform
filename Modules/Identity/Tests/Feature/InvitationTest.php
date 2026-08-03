<?php

declare(strict_types=1);

namespace Modules\Identity\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Identity\Domain\Models\Invitation;
use Modules\Identity\Domain\Models\User;
use Modules\Identity\Tests\Concerns\CreatesIdentityFixtures;
use Tests\TestCase;

/**
 * @see docs/03-services/identity/03-api.md
 */
final class InvitationTest extends TestCase
{
    use CreatesIdentityFixtures;
    use RefreshDatabase;

    public function test_an_owner_can_invite_someone(): void
    {
        ['token' => $token, 'organization_id' => $organizationId] = $this->registerOwner();

        $this->withToken($token)
            ->postJson("/api/v1/organizations/{$organizationId}/invitations", [
                'email' => 'john@gmail.com',
                'global_role_id' => $this->roleId('member'),
            ])
            ->assertCreated()
            ->assertJsonPath('data.email', 'john@gmail.com')
            ->assertJsonPath('data.role', 'member');
    }

    public function test_the_invitation_token_is_never_stored_in_clear_text(): void
    {
        ['token' => $token, 'organization_id' => $organizationId] = $this->registerOwner();

        $plainToken = $this->invite($token, $organizationId, 'john@gmail.com');

        $this->assertDatabaseMissing('invitations', ['token_hash' => $plainToken]);
        $this->assertDatabaseHas('invitations', ['token_hash' => Invitation::hash($plainToken)]);
    }

    public function test_a_plain_member_cannot_invite(): void
    {
        ['token' => $ownerToken, 'organization_id' => $organizationId] = $this->registerOwner();
        $memberToken = $this->joinAsMember($ownerToken, $organizationId, 'membre@sekuu.com');

        $this->withToken($memberToken)
            ->postJson("/api/v1/organizations/{$organizationId}/invitations", [
                'email' => 'john@gmail.com',
                'global_role_id' => $this->roleId('member'),
            ])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'INSUFFICIENT_PERMISSIONS');
    }

    public function test_an_invitation_cannot_target_another_organization(): void
    {
        ['token' => $token] = $this->registerOwner('nathan@sekuu.com', 'SOS Clinique');
        ['organization_id' => $foreignOrganizationId] = $this->registerOwner('autre@sekuu.com', 'Autre entreprise');

        // L'organisation de l'URL doit correspondre à celle du token : une URL
        // ne peut jamais élargir la portée d'un token.
        $this->withToken($token)
            ->postJson("/api/v1/organizations/{$foreignOrganizationId}/invitations", [
                'email' => 'john@gmail.com',
                'global_role_id' => $this->roleId('member'),
            ])
            ->assertNotFound()
            ->assertJsonPath('error.code', 'ORGANIZATION_NOT_FOUND');
    }

    public function test_accepting_an_invitation_creates_the_account_and_the_membership(): void
    {
        ['token' => $token, 'organization_id' => $organizationId] = $this->registerOwner();
        $plainToken = $this->invite($token, $organizationId, 'john@gmail.com');

        $this->flushHeaders();

        $this->postJson("/api/v1/invitations/{$plainToken}/accept", [
            'first_name' => 'John',
            'last_name' => 'Doe',
            'password' => 'un-mot-de-passe-long',
        ])
            ->assertOk()
            ->assertJsonPath('data.account_created', true)
            ->assertJsonPath('data.organization_id', $organizationId);

        $this->assertDatabaseHas('users', ['email' => 'john@gmail.com']);
    }

    public function test_the_address_is_considered_verified_after_acceptance(): void
    {
        ['token' => $token, 'organization_id' => $organizationId] = $this->registerOwner();
        $plainToken = $this->invite($token, $organizationId, 'john@gmail.com');

        $this->flushHeaders();
        $this->acceptAsNewUser($plainToken);

        // La réception du jeton prouve la maîtrise de l'adresse.
        $this->assertNotNull(User::query()->where('email', 'john@gmail.com')->firstOrFail()->email_verified_at);
    }

    public function test_the_invited_role_is_applied(): void
    {
        ['token' => $token, 'organization_id' => $organizationId] = $this->registerOwner();
        $plainToken = $this->invite($token, $organizationId, 'john@gmail.com', 'admin');

        $this->flushHeaders();
        $this->acceptAsNewUser($plainToken);

        $memberToken = $this->loginAndSwitch('john@gmail.com', $organizationId);

        $this->withToken($memberToken)->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.context.roles', ['admin']);
    }

    public function test_an_existing_account_joins_without_registration_fields(): void
    {
        $this->registerUser('john@gmail.com');
        $this->flushHeaders();

        ['token' => $token, 'organization_id' => $organizationId] = $this->registerOwner();
        $plainToken = $this->invite($token, $organizationId, 'john@gmail.com');

        $this->flushHeaders();

        $this->postJson("/api/v1/invitations/{$plainToken}/accept")
            ->assertOk()
            ->assertJsonPath('data.account_created', false);
    }

    public function test_an_invitation_cannot_be_hijacked_by_another_signed_in_account(): void
    {
        ['token' => $token, 'organization_id' => $organizationId] = $this->registerOwner();
        $plainToken = $this->invite($token, $organizationId, 'john@gmail.com');

        // Le propriétaire, connecté, tente d'accepter une invitation adressée
        // à quelqu'un d'autre.
        $this->withToken($token)->postJson("/api/v1/invitations/{$plainToken}/accept")
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'INVITATION_EMAIL_MISMATCH');
    }

    public function test_an_invitation_cannot_be_accepted_twice(): void
    {
        ['token' => $token, 'organization_id' => $organizationId] = $this->registerOwner();
        $plainToken = $this->invite($token, $organizationId, 'john@gmail.com');

        $this->flushHeaders();
        $this->acceptAsNewUser($plainToken);

        $this->postJson("/api/v1/invitations/{$plainToken}/accept")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'INVITATION_ALREADY_ACCEPTED');
    }

    public function test_an_expired_invitation_is_refused(): void
    {
        ['token' => $token, 'organization_id' => $organizationId] = $this->registerOwner();
        $plainToken = $this->invite($token, $organizationId, 'john@gmail.com');

        Invitation::query()->where('token_hash', Invitation::hash($plainToken))
            ->update(['expires_at' => now()->subDay()]);

        $this->flushHeaders();

        $this->postJson("/api/v1/invitations/{$plainToken}/accept", [
            'first_name' => 'John',
            'last_name' => 'Doe',
            'password' => 'un-mot-de-passe-long',
        ])
            ->assertStatus(410)
            ->assertJsonPath('error.code', 'INVITATION_EXPIRED');
    }

    public function test_a_revoked_invitation_becomes_indistinguishable_from_a_missing_one(): void
    {
        ['token' => $token, 'organization_id' => $organizationId] = $this->registerOwner();
        $plainToken = $this->invite($token, $organizationId, 'john@gmail.com');

        $invitationId = $this->withToken($token)
            ->getJson("/api/v1/organizations/{$organizationId}/invitations")
            ->assertOk()
            ->json('data.0.id');

        $this->withToken($token)->deleteJson("/api/v1/invitations/{$invitationId}")
            ->assertNoContent();

        $this->flushHeaders();

        $this->getJson("/api/v1/invitations/{$plainToken}")
            ->assertNotFound()
            ->assertJsonPath('error.code', 'INVITATION_NOT_FOUND');
    }

    public function test_an_unknown_token_is_refused(): void
    {
        $this->getJson('/api/v1/invitations/nimporte-quoi')
            ->assertNotFound()
            ->assertJsonPath('error.code', 'INVITATION_NOT_FOUND');
    }

    public function test_the_public_preview_exposes_only_what_is_needed(): void
    {
        ['token' => $token, 'organization_id' => $organizationId] = $this->registerOwner();
        $plainToken = $this->invite($token, $organizationId, 'john@gmail.com');

        $this->flushHeaders();

        $response = $this->getJson("/api/v1/invitations/{$plainToken}")
            ->assertOk()
            ->assertJsonPath('data.email', 'john@gmail.com')
            ->assertJsonPath('data.organization.name', 'SOS Clinique')
            ->assertJsonPath('data.requires_account', true);

        // Ni l'identifiant de l'invitation, ni celui de l'organisation, ni
        // l'inviteur ne sont exposés à un visiteur non authentifié.
        $data = $response->json('data');
        $this->assertArrayNotHasKey('id', $data);
        $this->assertArrayNotHasKey('invited_by', $data);
        $this->assertArrayNotHasKey('token_hash', $data);
    }

    public function test_an_existing_member_cannot_be_invited_again(): void
    {
        ['token' => $token, 'organization_id' => $organizationId] = $this->registerOwner();

        $this->withToken($token)
            ->postJson("/api/v1/organizations/{$organizationId}/invitations", [
                'email' => 'nathan@sekuu.com',
                'global_role_id' => $this->roleId('member'),
            ])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'ALREADY_MEMBER');
    }

    public function test_only_one_invitation_can_be_pending_per_address(): void
    {
        ['token' => $token, 'organization_id' => $organizationId] = $this->registerOwner();

        $this->invite($token, $organizationId, 'john@gmail.com');

        $this->withToken($token)
            ->postJson("/api/v1/organizations/{$organizationId}/invitations", [
                'email' => 'john@gmail.com',
                'global_role_id' => $this->roleId('member'),
            ])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'DUPLICATE_RESOURCE');
    }

    public function test_creating_an_account_requires_the_registration_fields(): void
    {
        ['token' => $token, 'organization_id' => $organizationId] = $this->registerOwner();
        $plainToken = $this->invite($token, $organizationId, 'john@gmail.com');

        $this->flushHeaders();

        $this->postJson("/api/v1/invitations/{$plainToken}/accept")
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');
    }

    private function invite(
        string $token,
        string $organizationId,
        string $email,
        string $role = 'member',
    ): string {
        return $this->withToken($token)
            ->postJson("/api/v1/organizations/{$organizationId}/invitations", [
                'email' => $email,
                'global_role_id' => $this->roleId($role),
            ])
            ->assertCreated()
            ->json('data.token');
    }

    private function acceptAsNewUser(string $plainToken): void
    {
        $this->postJson("/api/v1/invitations/{$plainToken}/accept", [
            'first_name' => 'John',
            'last_name' => 'Doe',
            'password' => 'un-mot-de-passe-long',
        ])->assertOk();
    }

    private function loginAndSwitch(string $email, string $organizationId): string
    {
        $token = $this->postJson('/api/v1/auth/login', [
            'email' => $email,
            'password' => 'un-mot-de-passe-long',
        ])->assertOk()->json('data.access_token');

        return $this->switchTo($token, $organizationId);
    }

    private function joinAsMember(string $ownerToken, string $organizationId, string $email): string
    {
        $plainToken = $this->invite($ownerToken, $organizationId, $email);

        $this->flushHeaders();
        $this->acceptAsNewUser($plainToken);

        return $this->loginAndSwitch($email, $organizationId);
    }
}
