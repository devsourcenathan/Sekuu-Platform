<?php

declare(strict_types=1);

namespace Modules\Identity\Infrastructure\Console;

use Illuminate\Console\Command;
use Modules\Identity\Domain\Models\PlatformOperator;
use Modules\Identity\Domain\Models\User;

/**
 * Habiliter quelqu'un à agir au nom de Sekuu.
 *
 * ## Pourquoi ce n'est pas une route, et ne le sera jamais
 *
 * Une route qui octroie des permissions de plateforme serait, le jour où un
 * compte est compromis, le moyen d'en fabriquer d'autres. La permission
 * `platform.operators` existe dans le modèle **pour être refusée**.
 *
 * L'habilitation se pose donc ici, par quelqu'un qui a déjà accès au serveur —
 * et là où il n'y a pas de shell, directement en base. Dans les deux cas, elle
 * suppose un accès que l'application ne peut pas accorder.
 *
 * @see docs/04-decisions/adr-0018-platform-operator.md
 */
final class ManageOperatorCommand extends Command
{
    protected $signature = 'identity:operator
        {email? : L utilisateur a habiliter}
        {--grant=* : Permissions a accorder, ou "all"}
        {--revoke : Retire toute habilitation}';

    protected $description = 'Liste les opérateurs de la plateforme, en habilite un, ou le révoque.';

    public function handle(): int
    {
        $email = $this->argument('email');

        if ($email === null) {
            return $this->list();
        }

        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            $this->error("Aucun utilisateur avec l'adresse « {$email} ».");

            return self::FAILURE;
        }

        return $this->option('revoke')
            ? $this->revoke($user)
            : $this->grant($user);
    }

    private function list(): int
    {
        $operators = PlatformOperator::query()->with('user')->orderBy('granted_at')->get();

        if ($operators->isEmpty()) {
            $this->info('Aucun opérateur. Personne ne peut administrer la plateforme.');
            $this->newLine();
            $this->comment('Habiliter : php artisan identity:operator vous@exemple.com --grant=platform.plans');

            return self::SUCCESS;
        }

        $this->table(
            ['Adresse', 'Permissions', 'Depuis', 'État'],
            $operators->map(fn (PlatformOperator $o): array => [
                $o->user?->email ?? '—',
                implode(', ', (array) $o->permissions),
                $o->granted_at?->toDateString(),
                $o->isActive() ? 'actif' : 'révoqué',
            ])->all(),
        );

        return self::SUCCESS;
    }

    private function grant(User $user): int
    {
        $demandees = (array) $this->option('grant');

        if ($demandees === []) {
            $this->error('Aucune permission demandée. Utilisez --grant, une fois par permission.');
            $this->comment('Disponibles : '.implode(', ', $this->grantable()));

            return self::FAILURE;
        }

        $permissions = in_array('all', $demandees, true) ? $this->grantable() : $demandees;
        $inconnues = array_diff($permissions, $this->grantable());

        if ($inconnues !== []) {
            $this->error('Permissions inconnues : '.implode(', ', $inconnues));
            $this->comment('Disponibles : '.implode(', ', $this->grantable()));

            return self::FAILURE;
        }

        PlatformOperator::query()->updateOrCreate(
            ['user_id' => $user->id],
            [
                'permissions' => array_values($permissions),
                'granted_at' => now(),
                'revoked_at' => null,
            ],
        );

        $this->info("{$user->email} est opérateur : ".implode(', ', $permissions));
        $this->newLine();
        $this->warn('Ce compte peut désormais lire des données appartenant à des clients.');
        $this->comment('Chaque appel — lectures comprises — entre au journal d\'audit.');
        $this->comment("Il n'y a pas de second facteur : un mot de passe suffit à s'en servir.");

        return self::SUCCESS;
    }

    /**
     * `platform.operators` n'est jamais accordable, même ici.
     *
     * La commande respecte la règle du modèle plutôt que de s'en dispenser :
     * une permission qui n'est honorée nulle part ne doit pas pouvoir être
     * octroyée, sinon quelqu'un la lira un jour dans la base et croira qu'elle
     * agit.
     *
     * @return list<string>
     */
    private function grantable(): array
    {
        return array_values(array_diff(PlatformOperator::ALL, [PlatformOperator::OPERATORS]));
    }

    private function revoke(User $user): int
    {
        $operator = PlatformOperator::query()->where('user_id', $user->id)->first();

        if ($operator === null) {
            $this->info("{$user->email} n'était pas opérateur.");

            return self::SUCCESS;
        }

        // Daté plutôt que supprimé : on garde la trace qu'un accès a existé.
        $operator->forceFill(['revoked_at' => now()])->save();

        $this->info("Habilitation de {$user->email} révoquée. Elle cesse d'agir immédiatement.");

        return self::SUCCESS;
    }
}
