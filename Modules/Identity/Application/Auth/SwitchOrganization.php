<?php

declare(strict_types=1);

namespace Modules\Identity\Application\Auth;

use App\Platform\Exceptions\DomainException;
use Modules\Identity\Domain\Models\User;
use Modules\Identity\Domain\Models\UserSession;
use Modules\Identity\Infrastructure\Jwt\IssuedAccessToken;

/**
 * Change l'organisation active du token.
 *
 * Le refresh token n'est pas touché : la session reste la même, seul le
 * contexte change.
 *
 * @see docs/02-standards/security.md
 */
final class SwitchOrganization
{
    public function __construct(private readonly SessionTokenService $tokens) {}

    public function handle(User $user, UserSession $session, string $organizationId): IssuedAccessToken
    {
        $membership = $user->activeMembershipIn($organizationId);

        // Une organisation dont l'utilisateur n'est pas membre doit être
        // indiscernable d'une organisation inexistante.
        if ($membership === null) {
            throw DomainException::notFound(
                'MEMBERSHIP_NOT_FOUND',
                __('You are not a member of this organization.'),
            );
        }

        if (! $membership->organization?->isActive()) {
            throw DomainException::forbidden(
                'ORGANIZATION_SUSPENDED',
                __('This organization is suspended.'),
            );
        }

        return $this->tokens->reissueAccessToken($user, $session, $membership);
    }
}
