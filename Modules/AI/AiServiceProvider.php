<?php

declare(strict_types=1);

namespace Modules\AI;

use App\Platform\Support\ModuleServiceProvider;
use Illuminate\Console\Scheduling\Schedule;
use Modules\AI\Application\Models\ModelDefinition;
use Modules\AI\Application\Models\ModelRegistry;
use Modules\AI\Application\Tasks\TaskDefinition;
use Modules\AI\Application\Tasks\TaskRegistry;
use Modules\AI\Infrastructure\Console\ListModelsCommand;
use Modules\AI\Infrastructure\Console\ManageAccountCommand;
use Modules\AI\Infrastructure\Console\ManageEndpointCommand;
use Modules\AI\Infrastructure\Console\SweepAiCommand;
use Modules\AI\Infrastructure\Console\VerifyAccountsCommand;
use Modules\AI\Infrastructure\Drivers\DriverRegistry;

final class AiServiceProvider extends ModuleServiceProvider
{
    protected function moduleSlug(): string
    {
        return 'ai';
    }

    protected function modulePath(): string
    {
        return __DIR__;
    }

    public function register(): void
    {
        $this->app->singleton(DriverRegistry::class, function ($app): DriverRegistry {
            $registry = new DriverRegistry($app);

            foreach ((array) config('ai.drivers', []) as $name => $driver) {
                $registry->register((string) $name, $driver);
            }

            return $registry;
        });

        $this->app->singleton(ModelRegistry::class, function (): ModelRegistry {
            $registry = new ModelRegistry;

            foreach ((array) config('ai.models', []) as $id => $model) {
                $registry->register(ModelDefinition::fromConfig((string) $id, (array) $model));
            }

            return $registry;
        });

        /*
         * Le catalogue des tâches : le seul vocabulaire qu'un appelant
         * connaisse.
         *
         * Il n'existe aucun champ `model` dans l'API — seule la plateforme
         * nomme le modèle, et c'est ici qu'elle le fait.
         */
        $this->app->singleton(TaskRegistry::class, function ($app): TaskRegistry {
            $registry = new TaskRegistry($app->make(ModelRegistry::class));

            foreach ((array) config('ai.tasks', []) as $name => $task) {
                $registry->register(TaskDefinition::fromConfig((string) $name, (array) $task));
            }

            return $registry;
        });
    }

    public function boot(): void
    {
        parent::boot();

        if ($this->app->runningInConsole()) {
            $this->commands([
                ListModelsCommand::class,
                ManageAccountCommand::class,
                ManageEndpointCommand::class,
                SweepAiCommand::class,
                VerifyAccountsCommand::class,
            ]);
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            /*
             * Quotidienne, et pas horaire.
             *
             * L'épreuve consomme de vrais jetons, contrairement à celle de
             * Storage qui écrit trente octets gratuits. Elle attrape ce qui se
             * casse **après** l'enregistrement — clé révoquée, crédit épuisé,
             * modèle retiré — et elle est aussi la reprise : un compte corrigé
             * se rallume seul, sans déploiement.
             *
             * 04:30, après Storage : deux épreuves qui se chevauchent sur une
             * petite machine se disputeraient la même minute de processeur pour
             * rien.
             */
            $schedule->command(VerifyAccountsCommand::class)
                ->dailyAt('04:30')
                ->withoutOverlapping()
                ->onOneServer();

            /*
             * Toutes les heures, et pas chaque nuit.
             *
             * Une génération abandonnée laisse un appelant à sonder une ligne
             * qui ne bougera plus. Attendre le lendemain pour le lui dire, alors
             * qu'un travailleur est mort il y a dix minutes, est une journée
             * pendant laquelle il croit qu'on travaille pour lui.
             *
             * L'effacement des contenus expirés voyage avec, et ne coûte rien de
             * plus : c'est une suppression indexée.
             */
            $schedule->command(SweepAiCommand::class)
                ->hourly()
                ->withoutOverlapping()
                ->onOneServer();
        });
    }
}
