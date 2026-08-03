<?php

declare(strict_types=1);

namespace Modules\Identity\Infrastructure\OAuth;

/**
 * Identité telle que rapportée par un fournisseur externe.
 */
final readonly class OAuthIdentity
{
    public function __construct(
        public string $providerId,
        public ?string $email,
        public ?string $firstName = null,
        public ?string $lastName = null,
        public ?string $avatarUrl = null,
    ) {}
}
