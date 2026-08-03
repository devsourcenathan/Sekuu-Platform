<?php

declare(strict_types=1);

namespace Modules\Identity\Application\OAuth;

use Modules\Identity\Domain\Models\User;

final readonly class OAuthOutcome
{
    public function __construct(
        public User $user,
        public bool $accountCreated,
        public bool $accountLinked,
    ) {}
}
