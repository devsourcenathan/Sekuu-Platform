<?php

declare(strict_types=1);

namespace Modules\Billing;

use App\Platform\Contracts\BillingContract;
use App\Platform\Support\ModuleServiceProvider;
use Illuminate\Console\Scheduling\Schedule;
use Modules\Billing\Infrastructure\Console\AdvanceLifecycleCommand;
use Modules\Billing\Infrastructure\Console\ReconcilePaymentsCommand;
use Modules\Billing\Infrastructure\Contracts\BillingGateway;
use Modules\Billing\Infrastructure\Providers\ProviderRegistry;
use Modules\Billing\Infrastructure\Webhooks\WebhookRegistry;

final class BillingServiceProvider extends ModuleServiceProvider
{
    protected function moduleSlug(): string
    {
        return 'billing';
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
            foreach ((array) config('billing.providers', []) as $provider) {
                $registry->register($provider);
            }

            return $registry;
        });

        $this->app->singleton(WebhookRegistry::class, function ($app): WebhookRegistry {
            $registry = new WebhookRegistry($app);

            foreach ((array) config('billing.webhooks', []) as $provider => $handler) {
                $registry->register($provider, $handler);
            }

            return $registry;
        });
    }

    public function boot(): void
    {
        parent::boot();

        // Billing expose ses limites aux autres modules, et **rien d'autre** :
        // il publie les quotas, il ne les fait pas respecter. Chaque module
        // compte le sien, parce que lui seul sait le compter.
        //
        // Lié par requête et non en singleton : la mémoïsation interne ne doit
        // pas survivre à un changement de plan.
        $this->app->bind(BillingContract::class, BillingGateway::class);

        if ($this->app->runningInConsole()) {
            $this->commands([ReconcilePaymentsCommand::class, AdvanceLifecycleCommand::class]);
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            // Toutes les cinq minutes : un callback perdu ne doit jamais laisser
            // un client débité sans accès plus longtemps que nécessaire.
            $schedule->command(ReconcilePaymentsCommand::class)
                ->everyFiveMinutes()
                ->withoutOverlapping()
                ->onOneServer();

            // Tôt le matin : une suspension doit être constatée avant que
            // l'organisation n'ouvre ses portes, pas au milieu de sa journée.
            $schedule->command(AdvanceLifecycleCommand::class)
                ->dailyAt('02:30')
                ->withoutOverlapping()
                ->onOneServer()
                ->runInBackground();
        });
    }
}
