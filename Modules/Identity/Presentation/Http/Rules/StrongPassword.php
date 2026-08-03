<?php

declare(strict_types=1);

namespace Modules\Identity\Presentation\Http\Rules;

use Illuminate\Validation\Rules\Password;

/**
 * Politique de mot de passe de la plateforme.
 *
 * Longueur minimale et vérification contre les fuites connues, sans règle de
 * composition : imposer majuscules et caractères spéciaux produit des mots de
 * passe prévisibles.
 *
 * @see docs/02-standards/security.md
 */
final class StrongPassword
{
    public static function rule(): Password
    {
        $password = Password::min((int) config('identity.password.min_length', 12));

        // La vérification contre les fuites connues fait un appel réseau :
        // elle reste désactivée en développement et en test.
        return config('identity.password.check_compromised')
            ? $password->uncompromised()
            : $password;
    }
}
