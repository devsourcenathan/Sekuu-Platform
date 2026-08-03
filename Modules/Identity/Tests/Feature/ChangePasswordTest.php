<?php

declare(strict_types=1);

namespace Modules\Identity\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Identity\Application\Audit\AuditAction;
use Modules\Identity\Domain\Models\AuditLog;
use Modules\Identity\Domain\Models\User;
use Modules\Identity\Tests\Concerns\CreatesIdentityFixtures;
use Tests\TestCase;

/**
 * @see docs/02-standards/security.md
 */
final class ChangePasswordTest extends TestCase
{
    use CreatesIdentityFixtures;
    use RefreshDatabase;

    private const PASSWORD = 'un-mot-de-passe-long';

    private const NEW_PASSWORD = 'un-nouveau-mot-de-passe';

    public function test_a_user_can_change_their_password(): void
    {
        [$token, $userId] = $this->registerAndIdentify();

        $this->withToken($token)->postJson("/api/v1/users/{$userId}/change-password", [
            'current_password' => self::PASSWORD,
            'password' => self::NEW_PASSWORD,
        ])->assertOk();

        $this->flushHeaders();

        $this->postJson('/api/v1/auth/login', [
            'email' => 'nathan@sekuu.com',
            'password' => self::NEW_PASSWORD,
        ])->assertOk();
    }

    /**
     * Différence essentielle avec la réinitialisation : l'utilisateur a prouvé
     * qu'il connaît son mot de passe, sa session courante est donc conservée.
     */
    public function test_the_current_session_survives_but_the_others_do_not(): void
    {
        [$current, $userId] = $this->registerAndIdentify();

        $this->flushHeaders();
        $other = $this->postJson('/api/v1/auth/login', [
            'email' => 'nathan@sekuu.com',
            'password' => self::PASSWORD,
        ])->assertOk()->json('data.access_token');

        $this->withToken($current)->postJson("/api/v1/users/{$userId}/change-password", [
            'current_password' => self::PASSWORD,
            'password' => self::NEW_PASSWORD,
        ])->assertOk();

        $this->withToken($current)->getJson('/api/v1/auth/me')->assertOk();

        $this->withToken($other)->getJson('/api/v1/auth/me')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'TOKEN_REVOKED');
    }

    public function test_the_current_password_must_be_correct(): void
    {
        [$token, $userId] = $this->registerAndIdentify();

        $this->withToken($token)->postJson("/api/v1/users/{$userId}/change-password", [
            'current_password' => 'ce-nest-pas-le-bon',
            'password' => self::NEW_PASSWORD,
        ])
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'INVALID_CREDENTIALS');
    }

    public function test_the_new_password_cannot_be_the_current_one(): void
    {
        [$token, $userId] = $this->registerAndIdentify();

        $this->withToken($token)->postJson("/api/v1/users/{$userId}/change-password", [
            'current_password' => self::PASSWORD,
            'password' => self::PASSWORD,
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'PASSWORD_RECENTLY_USED');
    }

    public function test_a_previous_password_cannot_be_reused(): void
    {
        [$token, $userId] = $this->registerAndIdentify();

        $this->withToken($token)->postJson("/api/v1/users/{$userId}/change-password", [
            'current_password' => self::PASSWORD,
            'password' => self::NEW_PASSWORD,
        ])->assertOk();

        $this->withToken($token)->postJson("/api/v1/users/{$userId}/change-password", [
            'current_password' => self::NEW_PASSWORD,
            'password' => self::PASSWORD,
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'PASSWORD_RECENTLY_USED');
    }

    public function test_nobody_can_change_someone_else_password(): void
    {
        $this->registerUser('victime@sekuu.com');
        $victimId = User::query()->where('email', 'victime@sekuu.com')->firstOrFail()->id;

        $this->flushHeaders();
        $attacker = $this->registerUser('intrus@sekuu.com');

        $this->withToken($attacker)->postJson("/api/v1/users/{$victimId}/change-password", [
            'current_password' => self::PASSWORD,
            'password' => self::NEW_PASSWORD,
        ])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'FORBIDDEN');
    }

    public function test_a_weak_password_is_refused(): void
    {
        [$token, $userId] = $this->registerAndIdentify();

        $this->withToken($token)->postJson("/api/v1/users/{$userId}/change-password", [
            'current_password' => self::PASSWORD,
            'password' => 'court',
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');
    }

    public function test_the_change_is_recorded_without_leaking_the_password(): void
    {
        [$token, $userId] = $this->registerAndIdentify();

        $this->withToken($token)->postJson("/api/v1/users/{$userId}/change-password", [
            'current_password' => self::PASSWORD,
            'password' => self::NEW_PASSWORD,
        ])->assertOk();

        $this->assertDatabaseHas('audit_logs', ['action' => AuditAction::PASSWORD_CHANGED]);

        foreach (AuditLog::query()->get() as $log) {
            $this->assertStringNotContainsString(self::NEW_PASSWORD, (string) json_encode($log->payload));
        }
    }

    /**
     * @return array{string, string}
     */
    private function registerAndIdentify(): array
    {
        $token = $this->registerUser();

        return [$token, User::query()->where('email', 'nathan@sekuu.com')->firstOrFail()->id];
    }
}
