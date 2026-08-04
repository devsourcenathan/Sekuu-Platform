<?php

declare(strict_types=1);

namespace Modules\Billing;

use App\Platform\Contracts\BillingContract;
use App\Platform\Support\ModuleServiceProvider;
use Illuminate\Console\Scheduling\Schedule;
use Modules\Billing\Infrastructure\Console\AdvanceLifecycleCommand;
use Modules\Billing\Infrastructure\Contracts\BillingGateway;

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
        // Billing expose ses limites aux autres modules, et **rien d'autre** :
        // il publie les quotas, il ne les fait pas respecter. Chaque module
        // compte le sien, parce que lui seul sait le compter.
        //
        // Lié par requête et non en singleton : la mémoïsation interne ne doit
        // pas survivre à un changement de plan.
        $this->app->bind(BillingContract::class, BillingGateway::class);
    }

    public function boot(): void
    {
        parent::boot();

        if ($this->app->runningInConsole()) {
            $this->commands([AdvanceLifecycleCommand::class]);
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
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
