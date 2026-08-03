<?php

declare(strict_types=1);

namespace Modules\Identity\Application\Invitations;

use Modules\Identity\Domain\Models\Invitation;

/**
 * Le jeton en clair n'existe qu'ici, le temps de le transmettre à Notify.
 * Il n'est jamais relu depuis la base, où seul son hachage est conservé.
 */
final readonly class IssuedInvitation
{
    public function __construct(
        public Invitation $invitation,
        public string $plainToken,
    ) {}
}
