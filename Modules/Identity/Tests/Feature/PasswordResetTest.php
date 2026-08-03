<?php

declare(strict_types=1);

namespace Modules\Identity\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Identity\Application\Audit\AuditAction;
use Modules\Identity\Domain\Models\ActionToken;
use Modules\Identity\Domain\Models\AuditLog;
use Modules\Identity\Domain\Models\User;
use Modules\Identity\Tests\Concerns\CreatesIdentityFixtures;
use Tests\TestCase;

/**
 * @see docs/02-standards/security.md
 */
final class PasswordResetTest extends TestCase
{
    use CreatesIdentityFixtures;
    use RefreshDatabase;

    private const PASSWORD = 'un-mot-de-passe-long';

    private const NEW_PASSWORD = 'un-nouveau-mot-de-passe';

    public function test_a_reset_can_be_requested_and_applied(): void
    {
        $this->registerUser();
        $this->flushHeaders();

        $token = $this->requestReset('nathan@sekuu.com');

        $this->postJson('/api/v1/auth/reset-password', [
            'token' => $token,
            'password' => self::NEW_PASSWORD,
        ])->assertOk();

        $this->postJson('/api/v1/auth/login', [
            'email' => 'nathan@sekuu.com',
            'password' => self::NEW_PASSWORD,
        ])->assertOk();

        $this->postJson('/api/v1/auth/login', [
            'email' => 'nathan@sekuu.com',
            'password' => self::PASSWORD,
        ])->assertStatus(401);
    }

    /**
     * Le point le plus important de cet endpoint : la réponse ne doit rien
     * apprendre sur l'existence du compte.
     */
    public function test_the_response_is_identical_whether_the_account_exists_or_not(): void
    {
        $this->registerUser();
        $this->flushHeaders();

        $existing = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'nathan@sekuu.com']);
        $unknown = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'inconnu@sekuu.com']);

        $existing->assertStatus(202);
        $unknown->assertStatus(202);

        $this->assertSame($existing->json('data.message'), $unknown->json('data.message'));
        $this->assertNull($unknown->json('data.token'));
    }

    public function test_a_reset_revokes_every_session_without_exception(): void
    {
        $first = $this->registerUser();
        $this->flushHeaders();

        $second = $this->postJson('/api/v1/auth/login', [
            'email' => 'nathan@sekuu.com',
            'password' => self::PASSWORD,
        ])->assertOk()->json('data.access_token');

        $token = $this->requestReset('nathan@sekuu.com');

        $this->postJson('/api/v1/auth/reset-password', [
            'token' => $token,
            'password' => self::NEW_PASSWORD,
        ])->assertOk();

        // On ignore quelle session appartient à l'attaquant : toutes tombent.
        foreach ([$first, $second] as $accessToken) {
            $this->withToken($accessToken)->getJson('/api/v1/auth/me')
                ->assertStatus(401)
                ->assertJsonPath('error.code', 'TOKEN_REVOKED');

            $this->flushHeaders();
        }
    }

    public function test_a_reset_token_cannot_be_used_twice(): void
    {
        $this->registerUser();
        $this->flushHeaders();

        $token = $this->requestReset('nathan@sekuu.com');

        $this->postJson('/api/v1/auth/reset-password', [
            'token' => $token,
            'password' => self::NEW_PASSWORD,
        ])->assertOk();

        $this->postJson('/api/v1/auth/reset-password', [
            'token' => $token,
            'password' => 'encore-un-autre-mot-de-passe',
        ])
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'RESET_TOKEN_INVALID');
    }

    public function test_requesting_a_new_link_invalidates_the_previous_one(): void
    {
        $this->registerUser();
        $this->flushHeaders();

        $first = $this->requestReset('nathan@sekuu.com');
        $second = $this->requestReset('nathan@sekuu.com');

        $this->assertNotSame($first, $second);

        $this->postJson('/api/v1/auth/reset-password', [
            'token' => $first,
            'password' => self::NEW_PASSWORD,
        ])
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'RESET_TOKEN_INVALID');

        $this->postJson('/api/v1/auth/reset-password', [
            'token' => $second,
            'password' => self::NEW_PASSWORD,
        ])->assertOk();
    }

    public function test_an_expired_token_is_refused(): void
    {
        $this->registerUser();
        $this->flushHeaders();

        $token = $this->requestReset('nathan@sekuu.com');

        ActionToken::query()->where('type', ActionToken::PASSWORD_RESET)
            ->update(['expires_at' => now()->subHour()]);

        $this->postJson('/api/v1/auth/reset-password', [
            'token' => $token,
            'password' => self::NEW_PASSWORD,
        ])
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'RESET_TOKEN_INVALID');
    }

    public function test_an_unknown_token_is_refused(): void
    {
        $this->postJson('/api/v1/auth/reset-password', [
            'token' => 'nimporte-quoi',
            'password' => self::NEW_PASSWORD,
        ])
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'RESET_TOKEN_INVALID');
    }

    public function test_the_current_password_cannot_be_reused(): void
    {
        $this->registerUser();
        $this->flushHeaders();

        $token = $this->requestReset('nathan@sekuu.com');

        $this->postJson('/api/v1/auth/reset-password', [
            'token' => $token,
            'password' => self::PASSWORD,
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'PASSWORD_RECENTLY_USED');
    }

    public function test_a_previous_password_cannot_be_reused(): void
    {
        $this->registerUser();
        $this->flushHeaders();

        $this->resetTo(self::NEW_PASSWORD);

        // Retour au mot de passe initial : il est encore dans l'historique.
        $token = $this->requestReset('nathan@sekuu.com');

        $this->postJson('/api/v1/auth/reset-password', [
            'token' => $token,
            'password' => self::PASSWORD,
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'PASSWORD_RECENTLY_USED');
    }

    public function test_the_history_only_ever_stores_hashes(): void
    {
        $this->registerUser();
        $this->flushHeaders();

        $this->resetTo(self::NEW_PASSWORD);

        $this->assertDatabaseMissing('password_histories', ['password_hash' => self::PASSWORD]);
        $this->assertDatabaseCount('password_histories', 1);
    }

    public function test_a_short_password_is_refused_at_reset(): void
    {
        $this->registerUser();
        $this->flushHeaders();

        $token = $this->requestReset('nathan@sekuu.com');

        $this->postJson('/api/v1/auth/reset-password', ['token' => $token, 'password' => 'court'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');
    }

    public function test_a_suspended_account_gets_no_reset_link(): void
    {
        $this->registerUser();
        $this->flushHeaders();

        User::query()->where('email', 'nathan@sekuu.com')->update(['status' => 'suspended']);

        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'nathan@sekuu.com'])
            ->assertStatus(202);

        $this->assertDatabaseCount('action_tokens', 1); // seul celui de vérification d'email
        $this->assertSame(
            0,
            ActionToken::query()->where('type', ActionToken::PASSWORD_RESET)->count(),
        );
    }

    public function test_the_reset_token_is_never_stored_in_clear_text(): void
    {
        $this->registerUser();
        $this->flushHeaders();

        $token = $this->requestReset('nathan@sekuu.com');

        $this->assertDatabaseMissing('action_tokens', ['token_hash' => $token]);
        $this->assertDatabaseHas('action_tokens', ['token_hash' => ActionToken::hash($token)]);
    }

    public function test_the_reset_is_recorded_in_the_audit_journal(): void
    {
        $this->registerUser();
        $this->flushHeaders();

        $this->resetTo(self::NEW_PASSWORD);

        $actions = AuditLog::query()->pluck('action')->all();

        $this->assertContains(AuditAction::PASSWORD_RESET_REQUESTED, $actions);
        $this->assertContains(AuditAction::PASSWORD_RESET, $actions);

        foreach (AuditLog::query()->get() as $log) {
            $this->assertStringNotContainsString(self::NEW_PASSWORD, (string) json_encode($log->payload));
        }
    }

    private function requestReset(string $email): string
    {
        return $this->postJson('/api/v1/auth/forgot-password', ['email' => $email])
            ->assertStatus(202)
            ->json('data.token');
    }

    private function resetTo(string $password): void
    {
        $this->postJson('/api/v1/auth/reset-password', [
            'token' => $this->requestReset('nathan@sekuu.com'),
            'password' => $password,
        ])->assertOk();
    }
}
