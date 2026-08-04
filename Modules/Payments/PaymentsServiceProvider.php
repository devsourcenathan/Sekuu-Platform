<?php

declare(strict_types=1);

namespace Modules\Payments;

use App\Platform\Support\ModuleServiceProvider;
use Illuminate\Console\Scheduling\Schedule;
use Modules\Payments\Application\Payments\PayableRegistry;
use Modules\Payments\Infrastructure\Console\ReconcilePaymentsCommand;
use Modules\Payments\Infrastructure\Providers\ProviderRegistry;
use Modules\Payments\Infrastructure\Webhooks\WebhookRegistry;

final class PaymentsServiceProvider extends ModuleServiceProvider
{
    protected function moduleSlug(): string
    {
        return 'payments';
    }

    protected function modulePath(): string
    {
        return __DIR__;
    }

    public function register(): void
    {
        $this->app->singleton(ProviderRegistry::class, function ($app): ProviderRegistry {
            $registry = new ProviderRegistry($app);

            // L'ordre vaut priorité. La bascule est **volontairement étroite** :
            // on ne réessaie chez le suivant que si l'invite n'est jamais partie
            // sur le téléphone du client.
            foreach ((array) config('payments.providers', []) as $provider) {
                $registry->register($provider);
            }

            return $registry;
        });

        $this->app->singleton(PayableRegistry::class, function ($app): PayableRegistry {
            $registry = new PayableRegistry($app);

            // Le seul endroit où ce module apprend que d'autres modules
            // existent — et encore, par configuration, jamais par un `use`.
            foreach ((array) config('payments.payables', []) as $type => $source) {
                $registry->register($type, $source);
            }

            return $registry;
        });

        $this->app->singleton(WebhookRegistry::class, function ($app): WebhookRegistry {
            $registry = new WebhookRegistry($app);

            foreach ((array) config('payments.webhooks', []) as $provider => $handler) {
                $registry->register($provider, $handler);
            }

            return $registry;
        });
    }

    public function boot(): void
    {
        parent::boot();

        if ($this->app->runningInConsole()) {
            $this->commands([ReconcilePaymentsCommand::class]);
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            // Toutes les cinq minutes : un callback perdu ne doit jamais laisser
            // un client débité sans accès plus longtemps que nécessaire.
            $schedule->command(ReconcilePaymentsCommand::class)
                ->everyFiveMinutes()
                ->withoutOverlapping()
                ->onOneServer();
        });
    }
}
