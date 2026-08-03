<?php

declare(strict_types=1);

namespace Modules\Identity\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Identity\Domain\Models\RefreshToken;
use Tests\TestCase;

/**
 * @see docs/02-standards/security.md
 */
final class RefreshTokenTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_refresh_token_yields_a_new_pair(): void
    {
        ['refresh_token' => $refreshToken] = $this->register();

        $response = $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $refreshToken])
            ->assertOk()
            ->assertJsonStructure(['data' => ['access_token', 'refresh_token']]);

        // La rotation impose un nouveau jeton : réutiliser le même serait
        // indétectable en cas de vol.
        $this->assertNotSame($refreshToken, $response->json('data.refresh_token'));
    }

    public function test_the_previous_refresh_token_is_revoked_after_rotation(): void
    {
        ['refresh_token' => $first] = $this->register();

        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $first])->assertOk();

        $this->assertNotNull(
            RefreshToken::query()->where('token_hash', RefreshToken::hash($first))->first()?->revoked_at
        );
    }

    public function test_replaying_a_used_refresh_token_revokes_the_whole_session(): void
    {
        ['refresh_token' => $first, 'access_token' => $accessToken] = $this->register();

        $second = $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $first])
            ->assertOk()
            ->json('data.refresh_token');

        // Rejouer le premier jeton est le signe d'un vol.
        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $first])
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'TOKEN_REVOKED');

        // Toute la session tombe, pas seulement le jeton rejoué : le jeton
        // légitime du voleur ou de la victime devient inutilisable.
        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $second])
            ->assertStatus(401);

        $this->withToken($accessToken)->getJson('/api/v1/auth/me')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'TOKEN_REVOKED');
    }

    public function test_an_unknown_refresh_token_is_rejected(): void
    {
        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => 'nimporte-quoi'])
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'INVALID_TOKEN');
    }

    public function test_the_refresh_token_is_never_stored_in_clear_text(): void
    {
        ['refresh_token' => $refreshToken] = $this->register();

        $this->assertDatabaseMissing('refresh_tokens', ['token_hash' => $refreshToken]);
        $this->assertDatabaseHas('refresh_tokens', ['token_hash' => RefreshToken::hash($refreshToken)]);
    }

    public function test_the_refresh_token_is_also_delivered_as_an_http_only_cookie(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'first_name' => 'Nathan',
            'last_name' => 'Tchinda',
            'email' => 'nathan@sekuu.com',
            'password' => 'un-mot-de-passe-long',
        ])->assertCreated();

        // Pas de déchiffrement : le groupe `api` ne chiffre pas les cookies, et
        // le jeton est de toute façon opaque et stocké haché côté serveur.
        $cookie = $response->getCookie(config('identity.refresh_token.cookie'), decrypt: false);

        $this->assertNotNull($cookie);
        $this->assertTrue($cookie->isHttpOnly(), 'Le refresh token doit être inaccessible au JavaScript.');
        $this->assertSame($response->json('data.refresh_token'), $cookie->getValue());
    }

    /**
     * @return array{access_token: string, refresh_token: string}
     */
    private function register(): array
    {
        $data = $this->postJson('/api/v1/auth/register', [
            'first_name' => 'Nathan',
            'last_name' => 'Tchinda',
            'email' => 'nathan@sekuu.com',
            'password' => 'un-mot-de-passe-long',
        ])->assertCreated()->json('data');

        return [
            'access_token' => $data['access_token'],
            'refresh_token' => $data['refresh_token'],
        ];
    }
}
