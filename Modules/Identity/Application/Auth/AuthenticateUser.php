<?php

declare(strict_types=1);

namespace Modules\Identity\Application\Auth;

use App\Platform\Exceptions\DomainException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Identity\Domain\Models\User;

/**
 * @see docs/02-standards/security.md
 */
final class AuthenticateUser
{
    /**
     * Hachage jetable, utilisé lorsque l'email est inconnu : sans lui, le temps
     * de réponse trahirait l'existence du compte.
     *
     * Il est produit par l'algorithme configuré, et non écrit en dur : un
     * hachage figé finirait par ne plus correspondre à l'algorithme courant,
     * et la vérification échouerait au lieu de simplement retourner false.
     */
    private static ?string $dummyHash = null;

    public function handle(string $email, string $password): User
    {
        $user = User::query()->where('email', $email)->first();

        $matches = $user !== null && $user->password_hash !== null
            ? Hash::check($password, $user->password_hash)
            : Hash::check($password, self::dummyHash());

        // La réponse ne distingue jamais « email inconnu » de « mot de passe
        // incorrect » : la distinction permettrait d'énumérer les comptes.
        if ($user === null || ! $matches) {
            throw new DomainException(
                'INVALID_CREDENTIALS',
                __('The provided credentials are incorrect.'),
                401,
            );
        }

        if (! $user->isActive()) {
            throw DomainException::forbidden('ACCOUNT_SUSPENDED', __('This account is not active.'));
        }

        $user->forceFill(['last_login_at' => now()])->save();

        return $user;
    }

    private static function dummyHash(): string
    {
        return self::$dummyHash ??= Hash::make(Str::random(40));
    }
}
