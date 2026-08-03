<?php

declare(strict_types=1);

namespace Modules\Identity\Application\Auth;

use Modules\Identity\Domain\Models\ActionToken;
use Modules\Identity\Domain\Models\User;

/**
 * Demande de réinitialisation.
 *
 * La réponse de l'API est identique que l'adresse existe ou non : ce cas
 * d'usage ne renvoie donc jamais d'erreur, seulement un jeton — ou rien.
 *
 * @see docs/02-standards/security.md
 */
final class RequestPasswordReset
{
    public function __construct(private readonly ActionTokenService $tokens) {}

    /**
     * @return array{user: User, token: string}|null null si aucun compte
     *                                               utilisable ne correspond
     */
    public function handle(string $email): ?array
    {
        $user = User::query()->where('email', $email)->first();

        // Un compte suspendu ne peut pas se réinitialiser un accès : la
        // réponse reste néanmoins indiscernable côté client.
        if ($user === null || ! $user->isActive()) {
            return null;
        }

        return [
            'user' => $user,
            'token' => $this->tokens->issue(
                $user,
                ActionToken::PASSWORD_RESET,
                (int) config('identity.tokens.password_reset_ttl'),
            ),
        ];
    }
}
