<?php

declare(strict_types=1);

namespace Modules\Identity\Domain;

use Modules\Identity\Domain\Models\User;
use Modules\Identity\Domain\Models\UserSession;

/**
 * Ce qui a été authentifié pour la requête courante : l'utilisateur, sa
 * session (l'appareil) et le contexte porté par le token.
 */
final readonly class AuthenticatedContext
{
    public function __construct(
        public User $user,
        public UserSession $session,
        public TokenContext $token,
    ) {}
}
