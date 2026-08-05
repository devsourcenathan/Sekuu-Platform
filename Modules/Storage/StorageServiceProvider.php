<?php

declare(strict_types=1);

namespace Modules\Storage;

use App\Platform\Support\ModuleServiceProvider;
use Illuminate\Console\Scheduling\Schedule;
use Modules\Storage\Application\Files\OwnerRegistry;
use Modules\Storage\Infrastructure\Console\ManageDestinationCommand;
use Modules\Storage\Infrastructure\Console\SweepStorageCommand;
use Modules\Storage\Infrastructure\Console\VerifyDestinationsCommand;
use Modules\Storage\Infrastructure\Drivers\DriverRegistry;

final class StorageServiceProvider extends ModuleServiceProvider
{
    protected function moduleSlug(): string
    {
        return 'storage';
    }

    protected function modulePath(): string
    {
        return __DIR__;
    }

    public function register(): void
    {
        $this->app->singleton(DriverRegistry::class, function ($app): DriverRegistry {
            $registry = new DriverRegistry($app);

            foreach ((array) config('storage.drivers', []) as $name => $driver) {
                $registry->register((string) $name, $driver);
            }

            return $registry;
        });

        $this->app->singleton(OwnerRegistry::class, function ($app): OwnerRegistry {
            $registry = new OwnerRegistry($app);

            // Le seul endroit où ce module apprend que d'autres modules
            // existent — et encore, par configuration, jamais par un `use`.
            foreach ((array) config('storage.owners', []) as $type => $owner) {
                $registry->register((string) $type, $owner);
            }

            return $registry;
        });
    }

    public function boot(): void
    {
        parent::boot();

        if ($this->app->runningInConsole()) {
            $this->commands([
                ManageDestinationCommand::class,
                SweepStorageCommand::class,
                VerifyDestinationsCommand::class,
            ]);
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            // Toutes les heures : une déclaration jamais confirmée occupe un
            // objet dans le magasin, donc de l'argent, sans que personne le
            // sache.
            $schedule->command(SweepStorageCommand::class)
                ->hourly()
                ->withoutOverlapping()
                ->onOneServer();

            /*
             * L'épreuve quotidienne attrape ce qui se casse **après**
             * l'enregistrement : une clé révoquée chez le fournisseur, un
             * compartiment supprimé, un droit retiré.
             *
             * Sans elle, une destination cassée se découvre au téléversement
             * suivant — c'est-à-dire par un client.
             */
            $schedule->command(VerifyDestinationsCommand::class)
                ->dailyAt('04:00')
                ->withoutOverlapping()
                ->onOneServer();
        });
    }
}
