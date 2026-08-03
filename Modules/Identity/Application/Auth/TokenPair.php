<?php

declare(strict_types=1);

namespace Modules\Identity\Application\Auth;

use Modules\Identity\Domain\Models\UserSession;
use Modules\Identity\Infrastructure\Jwt\IssuedAccessToken;

final readonly class TokenPair
{
    public function __construct(
        public IssuedAccessToken $accessToken,
        public string $refreshToken,
        public UserSession $session,
    ) {}
}
