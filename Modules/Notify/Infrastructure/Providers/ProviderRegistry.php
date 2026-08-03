<?php

declare(strict_types=1);

namespace Modules\Notify\Infrastructure\Providers;

use App\Platform\Exceptions\DomainException;
use Illuminate\Contracts\Container\Container;

/**
 * Fournisseurs disponibles par canal, ordonnés par priorité.
 *
 * @see docs/03-services/notify/01-overview.md
 */
final class ProviderRegistry
{
    /** @var array<string, list<class-string<MessageProvider>>> */
    private array $providers = [];

    public function __construct(private readonly Container $container) {}

    /**
     * @param  class-string<MessageProvider>  $provider
     */
    public function register(string $channel, string $provider): void
    {
        $this->providers[$channel][] = $provider;
    }

    /**
     * Fournisseurs d'un canal, du plus prioritaire au dernier recours.
     *
     * @return list<MessageProvider>
     */
    public function forChannel(string $channel): array
    {
        $classes = $this->providers[$channel] ?? [];

        if ($classes === []) {
            throw new DomainException(
                'CHANNEL_NOT_CONFIGURED',
                __('No provider is configured for the :channel channel.', ['channel' => $channel]),
                503,
            );
        }

        return array_map(fn (string $class) => $this->container->make($class), $classes);
    }

    public function hasChannel(string $channel): bool
    {
        return ($this->providers[$channel] ?? []) !== [];
    }
}
