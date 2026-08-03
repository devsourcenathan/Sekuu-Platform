<?php

declare(strict_types=1);

namespace Modules\Identity\Application\Auth;

use App\Platform\Exceptions\DomainException;
use Modules\Identity\Domain\Models\ActionToken;
use Modules\Identity\Domain\Models\User;

final class VerifyEmail
{
    public function __construct(private readonly ActionTokenService $tokens) {}

    public function handle(string $plainToken): User
    {
        $user = $this->tokens->consume($plainToken, ActionToken::EMAIL_VERIFICATION);

        if ($user->email_verified_at === null) {
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        return $user;
    }

    /**
     * Émet un nouveau lien de vérification pour un compte non encore vérifié.
     */
    public function issueFor(User $user): string
    {
        if ($user->email_verified_at !== null) {
            throw DomainException::conflict(
                'RESOURCE_CONFLICT',
                __('This email address is already verified.'),
            );
        }

        return $this->tokens->issue(
            $user,
            ActionToken::EMAIL_VERIFICATION,
            (int) config('identity.tokens.email_verification_ttl'),
        );
    }
}
