<?php

declare(strict_types=1);

namespace Modules\Identity\Tests\Feature;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * @see docs/02-standards/security.md
 */
final class JwksTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_public_keys_are_exposed_at_the_well_known_path(): void
    {
        $this->getJson('/.well-known/jwks.json')
            ->assertOk()
            ->assertJsonStructure(['keys' => [['kty', 'use', 'alg', 'kid', 'n', 'e']]])
            ->assertJsonPath('keys.0.alg', 'RS256')
            ->assertJsonPath('keys.0.kty', 'RSA');
    }

    public function test_the_document_never_exposes_the_private_key(): void
    {
        $body = $this->getJson('/.well-known/jwks.json')->getContent();

        $this->assertStringNotContainsString('PRIVATE KEY', $body);
        // `d` est l'exposant privé RSA : sa présence signerait une fuite.
        $this->assertArrayNotHasKey('d', $this->getJson('/.well-known/jwks.json')->json('keys.0'));
    }

    /**
     * C'est le test qui compte : un consommateur n'ayant que le document JWKS
     * doit pouvoir valider un token, sans détenir le moindre secret.
     */
    public function test_a_consumer_can_verify_a_token_using_only_the_published_keys(): void
    {
        $accessToken = $this->postJson('/api/v1/auth/register', [
            'first_name' => 'Nathan',
            'last_name' => 'Tchinda',
            'email' => 'nathan@sekuu.com',
            'password' => 'un-mot-de-passe-long',
        ])->assertCreated()->json('data.access_token');

        $jwks = $this->getJson('/.well-known/jwks.json')->json();

        $claims = (array) JWT::decode($accessToken, JWK::parseKeySet($jwks));

        $this->assertSame(config('identity.jwt.issuer'), $claims['iss']);
        $this->assertNotEmpty($claims['sub']);
        $this->assertNotEmpty($claims['sid']);
    }

    public function test_the_key_id_of_the_token_matches_the_published_key(): void
    {
        $accessToken = $this->postJson('/api/v1/auth/register', [
            'first_name' => 'Nathan',
            'last_name' => 'Tchinda',
            'email' => 'nathan@sekuu.com',
            'password' => 'un-mot-de-passe-long',
        ])->assertCreated()->json('data.access_token');

        [$header] = explode('.', $accessToken);
        $header = (array) json_decode(JWT::urlsafeB64Decode($header), true);

        $this->assertSame(
            $this->getJson('/.well-known/jwks.json')->json('keys.0.kid'),
            $header['kid'],
        );
    }
}
