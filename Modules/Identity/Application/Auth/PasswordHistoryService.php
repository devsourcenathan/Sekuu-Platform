<?php

declare(strict_types=1);

namespace Modules\Identity\Application\Auth;

use App\Platform\Exceptions\DomainException;
use Illuminate\Support\Facades\Hash;
use Modules\Identity\Domain\Models\PasswordHistory;
use Modules\Identity\Domain\Models\User;

/**
 * Empêche la réutilisation immédiate d'un mot de passe.
 *
 * @see docs/02-standards/security.md
 */
final class PasswordHistoryService
{
    public function assertNotRecentlyUsed(User $user, string $plainPassword): void
    {
        foreach ($this->recentHashes($user) as $hash) {
            if (Hash::check($plainPassword, $hash)) {
                throw DomainException::unprocessable(
                    'PASSWORD_RECENTLY_USED',
                    __('This password was used recently. Choose a different one.'),
                );
            }
        }
    }

    public function remember(User $user, string $hashedPassword): void
    {
        PasswordHistory::create([
            'user_id' => $user->id,
            'password_hash' => $hashedPassword,
        ]);

        $this->trim($user);
    }

    /**
     * @return list<string>
     */
    private function recentHashes(User $user): array
    {
        $hashes = PasswordHistory::query()
            ->where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->limit($this->depth())
            ->pluck('password_hash')
            ->all();

        // Le mot de passe courant compte toujours, même si l'historique a été
        // purgé ou n'a jamais été alimenté pour ce compte.
        if ($user->password_hash !== null) {
            array_unshift($hashes, $user->password_hash);
        }

        return array_values(array_unique($hashes));
    }

    private function trim(User $user): void
    {
        $keep = PasswordHistory::query()
            ->where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->limit($this->depth())
            ->pluck('id');

        PasswordHistory::query()
            ->where('user_id', $user->id)
            ->whereNotIn('id', $keep)
            ->delete();
    }

    private function depth(): int
    {
        return (int) config('identity.password.history', 5);
    }
}
