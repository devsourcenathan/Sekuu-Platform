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
 * @see docs/03-services/identity/03-api.md
 */
final class EmailVerificationTest extends TestCase
{
    use CreatesIdentityFixtures;
    use RefreshDatabase;

    public function test_registration_leaves_the_address_unverified(): void
    {
        $this->register();

        $this->assertNull(User::query()->where('email', 'nathan@sekuu.com')->firstOrFail()->email_verified_at);
    }

    public function test_the_address_can_be_verified_with_the_token(): void
    {
        $verificationToken = $this->register()['email_verification_token'];

        $this->flushHeaders();

        $this->postJson('/api/v1/auth/verify-email', ['token' => $verificationToken])
            ->assertOk()
            ->assertJsonPath('data.email', 'nathan@sekuu.com');

        $this->assertNotNull(User::query()->where('email', 'nathan@sekuu.com')->firstOrFail()->email_verified_at);
    }

    public function test_verification_does_not_require_being_signed_in(): void
    {
        $verificationToken = $this->register()['email_verification_token'];

        // Le lien est cliqué depuis une boîte mail, souvent sur un autre
        // appareil : exiger une session le rendrait inutilisable.
        $this->flushHeaders();

        $this->postJson('/api/v1/auth/verify-email', ['token' => $verificationToken])->assertOk();
    }

    public function test_a_verification_token_cannot_be_used_twice(): void
    {
        $verificationToken = $this->register()['email_verification_token'];

        $this->flushHeaders();

        $this->postJson('/api/v1/auth/verify-email', ['token' => $verificationToken])->assertOk();

        $this->postJson('/api/v1/auth/verify-email', ['token' => $verificationToken])
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'RESET_TOKEN_INVALID');
    }

    public function test_an_expired_verification_token_is_refused(): void
    {
        $verificationToken = $this->register()['email_verification_token'];

        ActionToken::query()->where('type', ActionToken::EMAIL_VERIFICATION)
            ->update(['expires_at' => now()->subDay()]);

        $this->flushHeaders();

        $this->postJson('/api/v1/auth/verify-email', ['token' => $verificationToken])
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'RESET_TOKEN_INVALID');
    }

    public function test_a_reset_token_cannot_be_used_to_verify_an_address(): void
    {
        $this->register();
        $this->flushHeaders();

        $resetToken = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'nathan@sekuu.com'])
            ->assertStatus(202)
            ->json('data.token');

        // Les jetons sont typés : un jeton de réinitialisation ne peut pas
        // servir de jeton de vérification, ni l'inverse.
        $this->postJson('/api/v1/auth/verify-email', ['token' => $resetToken])
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'RESET_TOKEN_INVALID');
    }

    public function test_a_signed_in_user_can_ask_for_a_new_link(): void
    {
        $data = $this->register();

        $newToken = $this->withToken($data['access_token'])
            ->postJson('/api/v1/auth/resend-verification')
            ->assertStatus(202)
            ->json('data.token');

        $this->assertNotSame($data['email_verification_token'], $newToken);

        $this->flushHeaders();

        // Le lien précédent est invalidé par l'émission du nouveau.
        $this->postJson('/api/v1/auth/verify-email', ['token' => $data['email_verification_token']])
            ->assertStatus(400);

        $this->postJson('/api/v1/auth/verify-email', ['token' => $newToken])->assertOk();
    }

    public function test_an_already_verified_address_cannot_request_a_new_link(): void
    {
        $data = $this->register();

        $this->flushHeaders();
        $this->postJson('/api/v1/auth/verify-email', ['token' => $data['email_verification_token']])->assertOk();

        $this->withToken($data['access_token'])->postJson('/api/v1/auth/resend-verification')
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'RESOURCE_CONFLICT');
    }

    public function test_resending_requires_authentication(): void
    {
        $this->postJson('/api/v1/auth/resend-verification')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }

    public function test_a_password_reset_also_proves_control_of_the_address(): void
    {
        $this->register();
        $this->flushHeaders();

        $resetToken = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'nathan@sekuu.com'])
            ->json('data.token');

        $this->postJson('/api/v1/auth/reset-password', [
            'token' => $resetToken,
            'password' => 'un-nouveau-mot-de-passe',
        ])->assertOk();

        // Recevoir le lien prouve la maîtrise de la boîte : demander en plus
        // une vérification serait redondant.
        $this->assertNotNull(User::query()->where('email', 'nathan@sekuu.com')->firstOrFail()->email_verified_at);
    }

    public function test_the_verification_token_is_never_stored_in_clear_text(): void
    {
        $verificationToken = $this->register()['email_verification_token'];

        $this->assertDatabaseMissing('action_tokens', ['token_hash' => $verificationToken]);
        $this->assertDatabaseHas('action_tokens', ['token_hash' => ActionToken::hash($verificationToken)]);
    }

    public function test_verification_is_recorded_in_the_audit_journal(): void
    {
        $verificationToken = $this->register()['email_verification_token'];

        $this->flushHeaders();
        $this->postJson('/api/v1/auth/verify-email', ['token' => $verificationToken])->assertOk();

        $actions = AuditLog::query()->pluck('action')->all();

        $this->assertContains(AuditAction::EMAIL_VERIFICATION_SENT, $actions);
        $this->assertContains(AuditAction::EMAIL_VERIFIED, $actions);
    }

    /**
     * @return array<string, mixed>
     */
    private function register(): array
    {
        return $this->postJson('/api/v1/auth/register', [
            'first_name' => 'Nathan',
            'last_name' => 'Tchinda',
            'email' => 'nathan@sekuu.com',
            'password' => 'un-mot-de-passe-long',
        ])->assertCreated()->json('data');
    }
}
