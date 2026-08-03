<?php

declare(strict_types=1);

namespace Modules\Identity\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Identity\Application\Audit\AuditAction;
use Modules\Identity\Domain\Models\User;
use Modules\Identity\Infrastructure\OAuth\OAuthGateway;
use Modules\Identity\Infrastructure\OAuth\OAuthIdentity;
use Modules\Identity\Tests\Concerns\CreatesIdentityFixtures;
use Modules\Identity\Tests\Doubles\FakeOAuthGateway;
use Tests\TestCase;

/**
 * @see docs/03-services/identity/03-api.md
 */
final class OAuthTest extends TestCase
{
    use CreatesIdentityFixtures;
    use RefreshDatabase;

    private FakeOAuthGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gateway = new FakeOAuthGateway;
        $this->app->instance(OAuthGateway::class, $this->gateway);
    }

    public function test_the_flow_starts_with_an_authorization_url_and_a_state(): void
    {
        $response = $this->getJson('/api/v1/oauth/google/redirect')
            ->assertOk()
            ->assertJsonStructure(['data' => ['authorization_url', 'state']]);

        $this->assertStringContainsString($response->json('data.state'), $response->json('data.authorization_url'));
    }

    public function test_an_unsupported_provider_is_refused(): void
    {
        $this->getJson('/api/v1/oauth/myspace/redirect')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'OAUTH_PROVIDER_NOT_SUPPORTED');
    }

    public function test_a_first_sign_in_creates_the_account(): void
    {
        $state = $this->startFlow();

        $this->getJson("/api/v1/oauth/google/callback?code=abc&state={$state}")
            ->assertOk()
            ->assertJsonPath('data.account_created', true)
            ->assertJsonPath('data.user.email', 'nathan@sekuu.com')
            ->assertJsonStructure(['data' => ['access_token', 'refresh_token']]);

        $user = User::query()->where('email', 'nathan@sekuu.com')->firstOrFail();

        $this->assertNull($user->password_hash, 'Un compte créé via OAuth n\'a pas de mot de passe.');
        $this->assertNotNull($user->email_verified_at, 'Google est un fournisseur de confiance.');
        $this->assertDatabaseHas('oauth_accounts', ['provider' => 'google', 'provider_id' => 'provider-user-1']);
    }

    public function test_a_returning_user_signs_in_without_creating_anything(): void
    {
        $this->signInWithGoogle();
        $this->flushHeaders();

        $this->getJson('/api/v1/oauth/google/callback?code=abc&state='.$this->startFlow())
            ->assertOk()
            ->assertJsonPath('data.account_created', false);

        $this->assertSame(1, User::query()->count());
        $this->assertDatabaseCount('oauth_accounts', 1);
    }

    /**
     * Le `state` protège du CSRF : sans lui, un attaquant pourrait faire
     * consommer son propre code d'autorisation par la victime.
     */
    public function test_a_callback_without_a_valid_state_is_refused(): void
    {
        $this->getJson('/api/v1/oauth/google/callback?code=abc&state=fabrique')
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'OAUTH_STATE_INVALID');
    }

    public function test_a_state_cannot_be_replayed(): void
    {
        $state = $this->startFlow();

        $this->getJson("/api/v1/oauth/google/callback?code=abc&state={$state}")->assertOk();
        $this->flushHeaders();

        $this->getJson("/api/v1/oauth/google/callback?code=abc&state={$state}")
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'OAUTH_STATE_INVALID');
    }

    public function test_a_state_issued_for_another_provider_is_refused(): void
    {
        $state = $this->startFlow('google');

        $this->getJson("/api/v1/oauth/github/callback?code=abc&state={$state}")
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'OAUTH_STATE_INVALID');
    }

    public function test_a_trusted_provider_links_to_an_existing_account(): void
    {
        $this->registerUser('nathan@sekuu.com');
        $this->flushHeaders();

        $this->getJson('/api/v1/oauth/google/callback?code=abc&state='.$this->startFlow())
            ->assertOk()
            ->assertJsonPath('data.account_created', false);

        $this->assertSame(1, User::query()->count());
        $this->assertDatabaseHas('oauth_accounts', ['provider' => 'google']);
    }

    /**
     * Rattacher un compte existant sur la seule foi de l'adresse permettrait
     * une prise de contrôle si le fournisseur ne vérifie pas ses emails.
     */
    public function test_an_untrusted_provider_cannot_claim_an_existing_account(): void
    {
        $this->registerUser('nathan@sekuu.com');
        $this->flushHeaders();

        $state = $this->startFlow('github');

        $this->getJson("/api/v1/oauth/github/callback?code=abc&state={$state}")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'OAUTH_EMAIL_TAKEN');

        $this->assertDatabaseCount('oauth_accounts', 0);
    }

    public function test_an_untrusted_provider_can_still_create_a_new_account(): void
    {
        $state = $this->startFlow('github');

        $this->getJson("/api/v1/oauth/github/callback?code=abc&state={$state}")
            ->assertOk()
            ->assertJsonPath('data.account_created', true);

        // L'adresse n'est pas considérée comme vérifiée pour autant.
        $this->assertNull(User::query()->where('email', 'nathan@sekuu.com')->firstOrFail()->email_verified_at);
    }

    public function test_a_suspended_account_cannot_sign_in_through_oauth(): void
    {
        $this->signInWithGoogle();
        User::query()->where('email', 'nathan@sekuu.com')->update(['status' => 'suspended']);
        $this->flushHeaders();

        $this->getJson('/api/v1/oauth/google/callback?code=abc&state='.$this->startFlow())
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'ACCOUNT_SUSPENDED');
    }

    public function test_a_provider_failure_is_reported_as_such(): void
    {
        $this->gateway->willFail();

        $this->getJson('/api/v1/oauth/google/callback?code=abc&state='.$this->startFlow())
            ->assertStatus(503)
            ->assertJsonPath('error.code', 'OAUTH_PROVIDER_ERROR');
    }

    public function test_a_provider_without_email_is_refused(): void
    {
        $this->gateway->willReturn(new OAuthIdentity(providerId: 'x', email: null));

        $this->getJson('/api/v1/oauth/google/callback?code=abc&state='.$this->startFlow())
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'OAUTH_PROVIDER_ERROR');
    }

    public function test_linked_accounts_are_listed(): void
    {
        $token = $this->signInWithGoogle();

        $this->withToken($token)->getJson('/api/v1/oauth/accounts')
            ->assertOk()
            ->assertJsonPath('data.0.provider', 'google')
            ->assertJsonPath('data.0.email', 'nathan@sekuu.com');
    }

    /**
     * Délier le dernier moyen de connexion enfermerait l'utilisateur dehors.
     */
    public function test_the_only_sign_in_method_cannot_be_unlinked(): void
    {
        $token = $this->signInWithGoogle();

        $accountId = $this->withToken($token)->getJson('/api/v1/oauth/accounts')->json('data.0.id');

        $this->withToken($token)->deleteJson("/api/v1/oauth/accounts/{$accountId}")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'RESOURCE_CONFLICT');
    }

    public function test_an_account_can_be_unlinked_when_a_password_exists(): void
    {
        $this->registerUser('nathan@sekuu.com');
        $this->flushHeaders();

        $token = $this->signInWithGoogle();

        $accountId = $this->withToken($token)->getJson('/api/v1/oauth/accounts')->json('data.0.id');

        $this->withToken($token)->deleteJson("/api/v1/oauth/accounts/{$accountId}")->assertNoContent();

        $this->assertDatabaseCount('oauth_accounts', 0);
        $this->assertDatabaseHas('audit_logs', ['action' => AuditAction::OAUTH_UNLINKED]);
    }

    public function test_a_linked_account_of_another_user_cannot_be_unlinked(): void
    {
        $victimToken = $this->signInWithGoogle();
        $accountId = $this->withToken($victimToken)->getJson('/api/v1/oauth/accounts')->json('data.0.id');

        $this->flushHeaders();
        $attacker = $this->registerUser('intrus@sekuu.com');

        $this->withToken($attacker)->deleteJson("/api/v1/oauth/accounts/{$accountId}")
            ->assertNotFound()
            ->assertJsonPath('error.code', 'RESOURCE_NOT_FOUND');
    }

    private function startFlow(string $provider = 'google'): string
    {
        return $this->getJson("/api/v1/oauth/{$provider}/redirect")->assertOk()->json('data.state');
    }

    private function signInWithGoogle(): string
    {
        return $this->getJson('/api/v1/oauth/google/callback?code=abc&state='.$this->startFlow())
            ->assertOk()
            ->json('data.access_token');
    }
}
