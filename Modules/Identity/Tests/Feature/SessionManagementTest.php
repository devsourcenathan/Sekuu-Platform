<?php

declare(strict_types=1);

namespace Modules\Identity\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Identity\Application\Audit\AuditAction;
use Modules\Identity\Tests\Concerns\CreatesIdentityFixtures;
use Tests\TestCase;

/**
 * @see docs/03-services/identity/03-api.md
 */
final class SessionManagementTest extends TestCase
{
    use CreatesIdentityFixtures;
    use RefreshDatabase;

    public function test_a_user_sees_their_connected_devices(): void
    {
        $first = $this->registerUser();
        $this->flushHeaders();
        $this->login();

        $sessions = $this->withToken($first)->getJson('/api/v1/sessions')
            ->assertOk()
            ->json('data');

        $this->assertCount(2, $sessions);
        $this->assertSame(
            1,
            collect($sessions)->where('is_current', true)->count(),
            'Une seule session doit être marquée comme courante.',
        );
    }

    public function test_a_revoked_session_disappears_from_the_list(): void
    {
        $first = $this->registerUser();
        $this->flushHeaders();
        $second = $this->login();

        $secondId = $this->currentSessionId($second);

        $this->withToken($first)->deleteJson("/api/v1/sessions/{$secondId}")->assertNoContent();

        $this->withToken($first)->getJson('/api/v1/sessions')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_revoking_a_session_signs_that_device_out(): void
    {
        $first = $this->registerUser();
        $this->flushHeaders();
        $second = $this->login();

        $secondId = $this->currentSessionId($second);

        $this->withToken($first)->deleteJson("/api/v1/sessions/{$secondId}")->assertNoContent();

        $this->withToken($second)->getJson('/api/v1/auth/me')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'TOKEN_REVOKED');

        // La session qui a déclenché la révocation reste valide.
        $this->withToken($first)->getJson('/api/v1/auth/me')->assertOk();
    }

    /**
     * Le test qui compte : la session d'autrui doit être indiscernable d'une
     * session inexistante.
     */
    public function test_a_session_of_another_user_cannot_be_revoked(): void
    {
        $victim = $this->registerUser('victime@sekuu.com');
        $victimSessionId = $this->withToken($victim)->getJson('/api/v1/sessions')->json('data.0.id');

        $this->flushHeaders();
        $attacker = $this->registerUser('intrus@sekuu.com');

        $this->withToken($attacker)->deleteJson("/api/v1/sessions/{$victimSessionId}")
            ->assertNotFound()
            ->assertJsonPath('error.code', 'RESOURCE_NOT_FOUND');

        $this->flushHeaders();
        $this->withToken($victim)->getJson('/api/v1/auth/me')->assertOk();
    }

    public function test_revoking_the_current_session_is_allowed(): void
    {
        $token = $this->registerUser();

        $sessionId = $this->withToken($token)->getJson('/api/v1/sessions')->json('data.0.id');

        $this->withToken($token)->deleteJson("/api/v1/sessions/{$sessionId}")->assertNoContent();

        $this->withToken($token)->getJson('/api/v1/auth/me')->assertStatus(401);
    }

    public function test_revocation_is_recorded_in_the_audit_journal(): void
    {
        $token = $this->registerUser();

        $sessionId = $this->withToken($token)->getJson('/api/v1/sessions')->json('data.0.id');
        $this->withToken($token)->deleteJson("/api/v1/sessions/{$sessionId}")->assertNoContent();

        $this->assertDatabaseHas('audit_logs', ['action' => AuditAction::SESSION_REVOKED]);
    }

    public function test_listing_sessions_requires_authentication(): void
    {
        $this->getJson('/api/v1/sessions')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }

    /**
     * Identifiant de la session portée par ce token précis — repéré par le
     * drapeau is_current, l'ordre de la liste n'étant pas un critère fiable.
     */
    private function currentSessionId(string $token): string
    {
        return collect($this->withToken($token)->getJson('/api/v1/sessions')->assertOk()->json('data'))
            ->firstWhere('is_current', true)['id'];
    }

    private function login(string $email = 'nathan@sekuu.com'): string
    {
        return $this->postJson('/api/v1/auth/login', [
            'email' => $email,
            'password' => 'un-mot-de-passe-long',
        ])->assertOk()->json('data.access_token');
    }
}
