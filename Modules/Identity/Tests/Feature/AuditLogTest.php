<?php

declare(strict_types=1);

namespace Modules\Identity\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Identity\Application\Audit\AuditAction;
use Modules\Identity\Application\Audit\AuditLogger;
use Modules\Identity\Domain\Models\AuditLog;
use Modules\Identity\Tests\Concerns\CreatesIdentityFixtures;
use RuntimeException;
use Tests\TestCase;

/**
 * @see docs/02-standards/security.md
 */
final class AuditLogTest extends TestCase
{
    use CreatesIdentityFixtures;
    use RefreshDatabase;

    public function test_sensitive_actions_are_recorded(): void
    {
        ['token' => $token, 'organization_id' => $organizationId] = $this->registerOwner();

        $this->withToken($token)->postJson('/api/v1/workspaces', ['name' => 'Douala'])->assertCreated();

        $actions = AuditLog::query()->pluck('action')->all();

        $this->assertContains(AuditAction::USER_REGISTERED, $actions);
        $this->assertContains(AuditAction::ORGANIZATION_CREATED, $actions);
        $this->assertContains(AuditAction::AUTH_ORGANIZATION_SWITCHED, $actions);
        $this->assertContains(AuditAction::WORKSPACE_CREATED, $actions);
    }

    public function test_a_failed_login_is_recorded(): void
    {
        $this->postJson('/api/v1/auth/login', [
            'email' => 'inconnu@sekuu.com',
            'password' => 'un-mot-de-passe-long',
        ])->assertStatus(401);

        $log = AuditLog::query()->where('action', AuditAction::AUTH_LOGIN_FAILED)->firstOrFail();

        $this->assertNull($log->user_id);
        $this->assertSame('inconnu@sekuu.com', $log->payload['email']);
        $this->assertSame('INVALID_CREDENTIALS', $log->payload['reason']);
    }

    public function test_a_token_replay_leaves_a_security_trace(): void
    {
        $data = $this->postJson('/api/v1/auth/register', [
            'first_name' => 'Nathan',
            'last_name' => 'Tchinda',
            'email' => 'nathan@sekuu.com',
            'password' => 'un-mot-de-passe-long',
        ])->assertCreated()->json('data');

        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $data['refresh_token']])->assertOk();
        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $data['refresh_token']])->assertStatus(401);

        $this->assertDatabaseHas('audit_logs', ['action' => AuditAction::AUTH_TOKEN_REPLAY_DETECTED]);
    }

    public function test_every_entry_carries_the_request_id(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'first_name' => 'Nathan',
            'last_name' => 'Tchinda',
            'email' => 'nathan@sekuu.com',
            'password' => 'un-mot-de-passe-long',
        ])->assertCreated();

        // C'est ce qui relie une entrée de journal à une réponse d'API.
        $this->assertSame(
            $response->json('meta.request_id'),
            AuditLog::query()->where('action', AuditAction::USER_REGISTERED)->firstOrFail()->request_id,
        );
    }

    public function test_the_journal_is_append_only(): void
    {
        $this->registerUser();

        $log = AuditLog::query()->firstOrFail();

        $this->expectException(RuntimeException::class);

        $log->update(['action' => 'falsifie']);
    }

    public function test_a_journal_entry_cannot_be_deleted(): void
    {
        $this->registerUser();

        $log = AuditLog::query()->firstOrFail();

        $this->expectException(RuntimeException::class);

        $log->delete();
    }

    /**
     * Le journal ne doit jamais devenir le point de fuite de ce que le reste
     * du système protège.
     */
    public function test_secrets_are_stripped_from_payloads(): void
    {
        $scrubbed = AuditLogger::scrub([
            'email' => 'nathan@sekuu.com',
            'password' => 'un-mot-de-passe-long',
            'refresh_token' => 'abc',
            'API-Key' => 'xyz',
            'nested' => ['token_hash' => 'def', 'name' => 'Douala'],
        ]);

        $this->assertSame(
            ['email' => 'nathan@sekuu.com', 'nested' => ['name' => 'Douala']],
            $scrubbed,
        );
    }

    public function test_no_recorded_payload_ever_contains_a_password(): void
    {
        ['token' => $token, 'organization_id' => $organizationId] = $this->registerOwner();

        $this->withToken($token)->postJson("/api/v1/organizations/{$organizationId}/invitations", [
            'email' => 'john@gmail.com',
            'global_role_id' => $this->roleId('member'),
        ])->assertCreated();

        foreach (AuditLog::query()->get() as $log) {
            $encoded = json_encode($log->payload);

            $this->assertStringNotContainsString('un-mot-de-passe-long', $encoded);
            $this->assertStringNotContainsString('password', $encoded);
        }
    }

    public function test_the_journal_is_readable_by_a_role_holding_audit_read(): void
    {
        ['token' => $token] = $this->registerOwner();

        $this->withToken($token)->getJson('/api/v1/audit-logs')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [['id', 'action', 'created_at', 'request_id']],
                'meta' => ['per_page', 'next_cursor', 'has_more', 'request_id'],
            ]);
    }

    public function test_the_journal_is_scoped_to_the_active_organization(): void
    {
        ['token' => $first] = $this->registerOwner('nathan@sekuu.com', 'SOS Clinique');
        $this->withToken($first)->postJson('/api/v1/workspaces', ['name' => 'Douala'])->assertCreated();

        ['token' => $second] = $this->registerOwner('autre@sekuu.com', 'Autre entreprise');

        $actions = collect(
            $this->withToken($second)->getJson('/api/v1/audit-logs')->assertOk()->json('data')
        )->pluck('action');

        // Aucune trace de l'activité de l'autre organisation.
        $this->assertFalse($actions->contains(AuditAction::WORKSPACE_CREATED));
    }

    public function test_a_role_without_audit_read_is_refused(): void
    {
        ['token' => $ownerToken, 'organization_id' => $organizationId] = $this->registerOwner();

        $plainToken = $this->withToken($ownerToken)
            ->postJson("/api/v1/organizations/{$organizationId}/invitations", [
                'email' => 'membre@sekuu.com',
                'global_role_id' => $this->roleId('member'),
            ])->assertCreated()->json('data.token');

        $this->flushHeaders();

        $this->postJson("/api/v1/invitations/{$plainToken}/accept", [
            'first_name' => 'Membre',
            'last_name' => 'Test',
            'password' => 'un-mot-de-passe-long',
        ])->assertOk();

        $memberToken = $this->switchTo(
            $this->postJson('/api/v1/auth/login', [
                'email' => 'membre@sekuu.com',
                'password' => 'un-mot-de-passe-long',
            ])->json('data.access_token'),
            $organizationId,
        );

        $this->withToken($memberToken)->getJson('/api/v1/audit-logs')
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'INSUFFICIENT_PERMISSIONS');
    }

    public function test_an_unknown_filter_is_rejected_rather_than_ignored(): void
    {
        ['token' => $token] = $this->registerOwner();

        $this->withToken($token)->getJson('/api/v1/audit-logs?filter[secret]=x')
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'INVALID_FILTER');
    }

    public function test_the_journal_can_be_paged_through_with_a_cursor(): void
    {
        ['token' => $token] = $this->registerOwner();

        foreach (['Douala', 'Yaoundé', 'Bafoussam'] as $name) {
            $this->withToken($token)->postJson('/api/v1/workspaces', ['name' => $name])->assertCreated();
        }

        $first = $this->withToken($token)->getJson('/api/v1/audit-logs?per_page=2')->assertOk();

        $first->assertJsonCount(2, 'data');
        $this->assertTrue($first->json('meta.has_more'));

        $cursor = $first->json('meta.next_cursor');

        $second = $this->withToken($token)
            ->getJson('/api/v1/audit-logs?per_page=2&cursor='.$cursor)
            ->assertOk();

        // Les pages ne se recouvrent pas : c'est l'intérêt du curseur.
        $this->assertEmpty(array_intersect(
            collect($first->json('data'))->pluck('id')->all(),
            collect($second->json('data'))->pluck('id')->all(),
        ));
    }
}
