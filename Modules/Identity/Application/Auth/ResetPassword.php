<?php

declare(strict_types=1);

namespace Modules\Identity\Application\Auth;

use Illuminate\Support\Facades\DB;
use Modules\Identity\Domain\Models\ActionToken;
use Modules\Identity\Domain\Models\User;
use Modules\Identity\Domain\Models\UserSession;

/**
 * @see docs/02-standards/security.md
 */
final class ResetPassword
{
    public function __construct(
        private readonly ActionTokenService $tokens,
        private readonly PasswordHistoryService $history,
    ) {}

    public function handle(string $plainToken, string $newPassword): User
    {
        $user = $this->tokens->consume($plainToken, ActionToken::PASSWORD_RESET);

        $this->history->assertNotRecentlyUsed($user, $newPassword);

        return DB::transaction(function () use ($user, $newPassword): User {
            $previousHash = $user->password_hash;

            // Le cast `hashed` du modèle applique l'algorithme configuré.
            $user->password_hash = $newPassword;

            // Recevoir le lien prouve la maîtrise de l'adresse : inutile de
            // demander en plus une vérification d'email.
            if ($user->email_verified_at === null) {
                $user->email_verified_at = now();
            }

            $user->save();

            if ($previousHash !== null) {
                $this->history->remember($user, $previousHash);
            }

            // Réinitialisation : toutes les sessions tombent, sans exception.
            // On ne sait pas laquelle appartient à l'attaquant.
            $this->revokeAllSessions($user);

            return $user;
        });
    }

    private function revokeAllSessions(User $user): void
    {
        UserSession::query()
            ->where('user_id', $user->id)
            ->whereNull('revoked_at')
            ->get()
            ->each(fn (UserSession $session) => $session->revoke());
    }
}
