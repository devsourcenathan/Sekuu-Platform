<?php

declare(strict_types=1);

namespace Modules\Identity\Application\Auth;

use App\Platform\Exceptions\DomainException;
use Illuminate\Database\QueryException;
use Modules\Identity\Domain\Models\User;

final class RegisterUser
{
    /**
     * @param  array{first_name: string, last_name: string, email: string, password: string, language?: string, timezone?: string}  $attributes
     */
    public function handle(array $attributes): User
    {
        $user = new User([
            'first_name' => $attributes['first_name'],
            'last_name' => $attributes['last_name'],
            'email' => $attributes['email'],
            'language' => $attributes['language'] ?? 'fr',
            'timezone' => $attributes['timezone'] ?? 'UTC',
        ]);

        // Le cast `hashed` du modèle applique le hachage configuré (Argon2id).
        $user->password_hash = $attributes['password'];

        try {
            $user->save();
        } catch (QueryException $e) {
            // L'unicité est garantie par l'index partiel : on ne fait pas de
            // pré-vérification, qui laisserait une fenêtre de concurrence.
            if ($this->isUniqueViolation($e)) {
                throw DomainException::conflict(
                    'EMAIL_ALREADY_USED',
                    __('identity::messages.email_already_used'),
                );
            }

            throw $e;
        }

        // L'email de bienvenue et le lien de vérification relèvent de Notify :
        // Identity publiera UserRegistered dès que le module existera.

        return $user;
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        // 23505 = unique_violation (PostgreSQL) ; SQLite remonte le message brut.
        return $e->getCode() === '23505'
            || str_contains(strtolower($e->getMessage()), 'unique');
    }
}
