<?php

declare(strict_types=1);

namespace Modules\Identity\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Identity\Domain\Models\User;
use Tests\TestCase;

/**
 * @see docs/03-services/identity/03-api.md
 */
final class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_visitor_can_register_and_receives_a_token(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'first_name' => 'Nathan',
            'last_name' => 'Tchinda',
            'email' => 'nathan@sekuu.com',
            'password' => 'un-mot-de-passe-long',
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.token_type', 'Bearer')
            ->assertJsonPath('data.user.email', 'nathan@sekuu.com')
            ->assertJsonStructure(['data' => ['access_token', 'refresh_token', 'expires_in']]);

        $this->assertDatabaseHas('users', ['email' => 'nathan@sekuu.com']);
    }

    public function test_registration_never_returns_the_password(): void
    {
        $response = $this->postJson('/api/v1/auth/register', $this->registrationPayload());

        $this->assertStringNotContainsString('un-mot-de-passe-long', $response->getContent());
        $this->assertArrayNotHasKey('password_hash', $response->json('data.user'));
    }

    public function test_a_short_password_is_rejected(): void
    {
        $this->postJson('/api/v1/auth/register', [
            ...$this->registrationPayload(),
            'password' => 'court',
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR')
            ->assertJsonStructure(['error' => ['details' => ['password']]]);
    }

    public function test_an_email_cannot_be_registered_twice(): void
    {
        $this->postJson('/api/v1/auth/register', $this->registrationPayload())->assertCreated();

        $this->postJson('/api/v1/auth/register', $this->registrationPayload())
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'EMAIL_ALREADY_USED');
    }

    public function test_a_registered_user_can_log_in(): void
    {
        $this->postJson('/api/v1/auth/register', $this->registrationPayload())->assertCreated();

        $this->postJson('/api/v1/auth/login', [
            'email' => 'nathan@sekuu.com',
            'password' => 'un-mot-de-passe-long',
        ])
            ->assertOk()
            ->assertJsonPath('data.user.email', 'nathan@sekuu.com')
            ->assertJsonStructure(['data' => ['access_token', 'refresh_token']]);
    }

    public function test_login_does_not_reveal_whether_the_account_exists(): void
    {
        $this->postJson('/api/v1/auth/register', $this->registrationPayload())->assertCreated();

        $unknownAccount = $this->postJson('/api/v1/auth/login', [
            'email' => 'inconnu@sekuu.com',
            'password' => 'un-mot-de-passe-long',
        ]);

        $wrongPassword = $this->postJson('/api/v1/auth/login', [
            'email' => 'nathan@sekuu.com',
            'password' => 'un-autre-mot-de-passe',
        ]);

        // Les deux cas doivent être indiscernables : sinon l'API permet
        // d'énumérer les comptes existants.
        $unknownAccount->assertStatus(401)->assertJsonPath('error.code', 'INVALID_CREDENTIALS');
        $wrongPassword->assertStatus(401)->assertJsonPath('error.code', 'INVALID_CREDENTIALS');

        $this->assertSame(
            $unknownAccount->json('error.message'),
            $wrongPassword->json('error.message'),
        );
    }

    public function test_a_suspended_account_cannot_log_in(): void
    {
        $this->postJson('/api/v1/auth/register', $this->registrationPayload())->assertCreated();

        User::query()->where('email', 'nathan@sekuu.com')->update(['status' => 'suspended']);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'nathan@sekuu.com',
            'password' => 'un-mot-de-passe-long',
        ])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'ACCOUNT_SUSPENDED');
    }

    public function test_me_requires_a_token(): void
    {
        $this->getJson('/api/v1/auth/me')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }

    public function test_me_returns_the_profile_and_an_empty_context_without_organization(): void
    {
        $token = $this->registerAndGetToken();

        $this->withToken($token)->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.user.email', 'nathan@sekuu.com')
            ->assertJsonPath('data.context.organization_id', null)
            ->assertJsonPath('data.context.roles', [])
            ->assertJsonPath('data.organizations', []);
    }

    public function test_a_forged_token_is_rejected(): void
    {
        $this->withToken('ceci.nest.pas-un-token')->getJson('/api/v1/auth/me')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'INVALID_TOKEN');
    }

    public function test_logout_revokes_the_session_and_its_token(): void
    {
        $token = $this->registerAndGetToken();

        $this->withToken($token)->postJson('/api/v1/auth/logout')->assertOk();

        // L'access token reste cryptographiquement valide, mais la session
        // révoquée le rend inutilisable.
        $this->withToken($token)->getJson('/api/v1/auth/me')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'TOKEN_REVOKED');
    }

    /**
     * @return array<string, string>
     */
    private function registrationPayload(): array
    {
        return [
            'first_name' => 'Nathan',
            'last_name' => 'Tchinda',
            'email' => 'nathan@sekuu.com',
            'password' => 'un-mot-de-passe-long',
        ];
    }

    private function registerAndGetToken(): string
    {
        return $this->postJson('/api/v1/auth/register', $this->registrationPayload())
            ->assertCreated()
            ->json('data.access_token');
    }
}
