<?php

declare(strict_types=1);

namespace Modules\Identity\Infrastructure\Jwt;

final readonly class IssuedAccessToken
{
    public function __construct(
        public string $token,
        public int $expiresIn,
        public int $expiresAt,
    ) {}
}
