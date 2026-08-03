<?php

declare(strict_types=1);

namespace Modules\Identity\Infrastructure\Jwt;

use App\Platform\Exceptions\DomainException;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Modules\Identity\Domain\TokenContext;
use Throwable;

/**
 * Vérifie un access token exactement dans l'ordre prescrit : signature, iss,
 * aud, exp/nbf.
 *
 * @see docs/02-standards/security.md
 */
final class AccessTokenVerifier
{
    public function __construct(
        private readonly SigningKeys $keys,
        private readonly string $issuer,
        /** @var list<string> */
        private readonly array $audience,
        private readonly int $leeway,
    ) {}

    public function verify(string $token): TokenContext
    {
        JWT::$leeway = $this->leeway;

        try {
            $claims = (array) JWT::decode($token, new Key($this->keys->publicKey(), 'RS256'));
        } catch (ExpiredException) {
            throw new DomainException('TOKEN_EXPIRED', __('The access token has expired.'), 401);
        } catch (Throwable) {
            throw new DomainException('INVALID_TOKEN', __('The access token is invalid.'), 401);
        }

        $this->assertIssuer($claims);
        $this->assertAudience($claims);

        return new TokenContext(
            userId: (string) ($claims['sub'] ?? ''),
            sessionId: (string) ($claims['sid'] ?? ''),
            organizationId: isset($claims['org']) ? (string) $claims['org'] : null,
            workspaceId: isset($claims['ws']) ? (string) $claims['ws'] : null,
            roles: array_values((array) ($claims['roles'] ?? [])),
            scopes: array_values((array) ($claims['scopes'] ?? [])),
            products: array_values((array) ($claims['products'] ?? [])),
            language: (string) ($claims['lang'] ?? 'fr'),
            tokenId: isset($claims['jti']) ? (string) $claims['jti'] : null,
        );
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    private function assertIssuer(array $claims): void
    {
        if (($claims['iss'] ?? null) !== $this->issuer) {
            throw new DomainException('INVALID_TOKEN', __('The access token was issued by an unknown party.'), 401);
        }
    }

    /**
     * Un consommateur doit rejeter un token dont il n'est pas destinataire.
     *
     * @param  array<string, mixed>  $claims
     */
    private function assertAudience(array $claims): void
    {
        $tokenAudience = (array) ($claims['aud'] ?? []);

        if (array_intersect($tokenAudience, $this->audience) === []) {
            throw new DomainException('INVALID_TOKEN', __('The access token is not intended for this service.'), 401);
        }
    }
}
