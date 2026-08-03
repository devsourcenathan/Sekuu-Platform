<?php

declare(strict_types=1);

namespace Modules\Identity\Application\Auth;

use App\Platform\Exceptions\DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Modules\Identity\Domain\Models\User;
use Modules\Identity\Domain\Models\UserSession;

/**
 * Changement de mot de passe depuis le profil.
 *
 * À la différence d'une réinitialisation, l'utilisateur prouve ici qu'il
 * connaît son mot de passe actuel : sa session courante est donc conservée.
 *
 * @see docs/02-standards/security.md
 */
final class ChangePassword
{
    public function __construct(private readonly PasswordHistoryService $history) {}

    public function handle(
        User $user,
        string $currentPassword,
        string $newPassword,
        ?UserSession $keepSession = null,
    ): User {
        if ($user->password_hash === null) {
            throw DomainException::conflict(
                'RESOURCE_CONFLICT',
                __('This account has no password. Use the password reset flow to set one.'),
            );
        }

        if (! Hash::check($currentPassword, $user->password_hash)) {
            throw new DomainException(
                'INVALID_CREDENTIALS',
                __('The current password is incorrect.'),
                401,
            );
        }

        $this->history->assertNotRecentlyUsed($user, $newPassword);

        return DB::transaction(function () use ($user, $newPassword, $keepSession): User {
            $previousHash = $user->password_hash;

            $user->password_hash = $newPassword;
            $user->save();

            $this->history->remember($user, $previousHash);

            $this->revokeOtherSessions($user, $keepSession);

            return $user;
        });
    }

    /**
     * Toutes les autres sessions tombent : si le mot de passe a fuité, les
     * appareils de l'attaquant sont déconnectés immédiatement.
     */
    private function revokeOtherSessions(User $user, ?UserSession $keepSession): void
    {
        UserSession::query()
            ->where('user_id', $user->id)
            ->whereNull('revoked_at')
            ->when($keepSession !== null, fn ($q) => $q->whereKeyNot($keepSession->id))
            ->get()
            ->each(fn (UserSession $session) => $session->revoke());
    }
}
