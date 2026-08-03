<?php

declare(strict_types=1);

namespace Modules\Notify;

use App\Platform\Events\DomainEvent;
use App\Platform\Support\ModuleServiceProvider;
use Illuminate\Support\Facades\Event;
use Modules\Notify\Application\Events\DomainEventSubscriber;
use Modules\Notify\Infrastructure\Providers\ProviderRegistry;

final class NotifyServiceProvider extends ModuleServiceProvider
{
    protected function moduleSlug(): string
    {
        return 'notify';
    }

    protected function modulePath(): string
    {
        return __DIR__;
    }

    public function register(): void
    {
        $this->app->singleton(ProviderRegistry::class, function ($app): ProviderRegistry {
            $registry = new ProviderRegistry($app);

            // L'ordre vaut priorité : le premier fournisseur est essayé
            // d'abord, les suivants servent de bascule sur échec
            // infrastructurel.
            foreach ((array) config('notify.providers', []) as $channel => $providers) {
                foreach ((array) $providers as $provider) {
                    $registry->register($channel, $provider);
                }
            }

            return $registry;
        });
    }

    public function boot(): void
    {
        parent::boot();

        // Notify écoute l'événement générique de la plateforme, pas des classes
        // d'un autre module : aucune dépendance de compilation vers Identity.
        Event::listen(DomainEvent::class, [DomainEventSubscriber::class, 'handle']);
    }
}
