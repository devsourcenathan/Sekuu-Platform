<?php

declare(strict_types=1);

namespace Modules\AI;

use App\Platform\Support\ModuleServiceProvider;
use Modules\AI\Application\Models\ModelDefinition;
use Modules\AI\Application\Models\ModelRegistry;
use Modules\AI\Application\Tasks\TaskDefinition;
use Modules\AI\Application\Tasks\TaskRegistry;
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
}
