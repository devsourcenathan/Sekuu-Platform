<?php

declare(strict_types=1);

namespace Modules\Identity\Infrastructure\Jwt;

use Firebase\JWT\JWT;
use Illuminate\Support\Str;
use Modules\Identity\Domain\TokenContext;

final class AccessTokenIssuer
{
    public function __construct(
        private readonly SigningKeys $keys,
        private readonly string $issuer,
        /** @var list<string> */
        private readonly array $audience,
        private readonly int $ttl,
    ) {}

    public function issue(TokenContext $context): IssuedAccessToken
    {
        $issuedAt = time();
        $expiresAt = $issuedAt + $this->ttl;

        $claims = [
            'iss' => $this->issuer,
            'aud' => $this->audience,
            'sub' => $context->userId,
            'sid' => $context->sessionId,
            'lang' => $context->language,
            'iat' => $issuedAt,
            'exp' => $expiresAt,
            'jti' => $context->tokenId ?? (string) Str::uuid(),
        ];

        // Les claims de contexte n'apparaissent que lorsqu'une organisation
        // est active : un token sans `org` n'ouvre que les routes de profil.
        if ($context->hasOrganization()) {
            $claims['org'] = $context->organizationId;
            $claims['roles'] = $context->roles;
            $claims['scopes'] = $context->scopes;
            $claims['products'] = $context->products;

            if ($context->workspaceId !== null) {
                $claims['ws'] = $context->workspaceId;
            }
        }

        $token = JWT::encode(
            $claims,
            $this->keys->privateKey(),
            'RS256',
            $this->keys->keyId(),
        );

        return new IssuedAccessToken($token, $this->ttl, $expiresAt);
    }
}
