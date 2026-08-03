<?php

declare(strict_types=1);

namespace Modules\Identity\Application\Invitations;

use Modules\Identity\Domain\Models\Membership;
use Modules\Identity\Domain\Models\User;

final readonly class AcceptedInvitation
{
    public function __construct(
        public User $user,
        public Membership $membership,
        /** Vrai si le compte a été créé au moment de l'acceptation. */
        public bool $accountCreated,
    ) {}
}
