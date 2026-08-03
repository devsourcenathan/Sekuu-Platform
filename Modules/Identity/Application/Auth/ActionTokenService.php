<?php

declare(strict_types=1);

namespace Modules\Identity\Application\Auth;

use App\Platform\Exceptions\DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Identity\Domain\Models\ActionToken;
use Modules\Identity\Domain\Models\User;

/**
 * Émission et consommation des jetons d'action à usage unique.
 *
 * @see docs/02-standards/security.md
 */
final class ActionTokenService
{
    /**
     * Émet un jeton et invalide tout jeton du même type encore en attente :
     * demander un nouveau lien doit rendre le précédent inutilisable.
     */
    public function issue(User $user, string $type, int $ttl): string
    {
        return DB::transaction(function () use ($user, $type, $ttl): string {
            ActionToken::query()
                ->where('user_id', $user->id)
                ->where('type', $type)
                ->whereNull('consumed_at')
                ->update(['consumed_at' => now()]);

            $plainToken = Str::random(64);

            ActionToken::create([
                'user_id' => $user->id,
                'type' => $type,
                'token_hash' => ActionToken::hash($plainToken),
                'expires_at' => now()->addSeconds($ttl),
            ]);

            return $plainToken;
        });
    }

    /**
     * Consomme un jeton et renvoie son porteur.
     *
     * Toutes les causes d'échec — jeton inconnu, déjà utilisé, expiré, ou d'un
     * autre type — produisent la même réponse : distinguer permettrait de
     * sonder l'état des jetons.
     */
    public function consume(string $plainToken, string $type): User
    {
        return DB::transaction(function () use ($plainToken, $type): User {
            $token = ActionToken::query()
                ->where('token_hash', ActionToken::hash($plainToken))
                ->where('type', $type)
                ->lockForUpdate()
                ->first();

            if ($token === null || ! $token->isUsable()) {
                throw new DomainException(
                    'RESET_TOKEN_INVALID',
                    __('This link is invalid or has expired.'),
                    400,
                );
            }

            $token->forceFill(['consumed_at' => now()])->save();

            $user = $token->user()->first();

            if ($user === null) {
                throw new DomainException(
                    'RESET_TOKEN_INVALID',
                    __('This link is invalid or has expired.'),
                    400,
                );
            }

            return $user;
        });
    }
}
