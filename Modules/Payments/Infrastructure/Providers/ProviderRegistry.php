<?php

declare(strict_types=1);

namespace Modules\Payments\Infrastructure\Providers;

use App\Platform\Exceptions\DomainException;
use Illuminate\Contracts\Container\Container;

/**
 * Agrégateurs disponibles, ordonnés par priorité.
 *
 * L'ordre est celui de la configuration ; le réseau du payeur ne le change
 * pas, il ne fait qu'écarter ceux qui ne le couvrent pas.
 */
final class ProviderRegistry
{
    /** @var list<class-string<PaymentProvider>> */
    private array $providers = [];

    public function __construct(private readonly Container $container) {}

    /**
     * @param  class-string<PaymentProvider>  $provider
     */
    public function register(string $provider): void
    {
        $this->providers[] = $provider;
    }

    /**
     * Agrégateurs configurés couvrant cet opérateur, du premier rang au dernier.
     *
     * @return list<PaymentProvider>
     */
    public function forOperator(string $operator): array
    {
        $available = array_values(array_filter(
            $this->all(),
            static fn (PaymentProvider $provider) => $provider->supports($operator),
        ));

        if ($available === []) {
            throw new DomainException(
                'PROVIDER_UNAVAILABLE',
                __('payments::messages.provider_unavailable', ['operator' => $operator]),
                503,
            );
        }

        return $available;
    }

    public function byName(string $name): PaymentProvider
    {
        foreach ($this->all() as $provider) {
            if ($provider->name() === $name) {
                return $provider;
            }
        }

        throw new DomainException(
            'PROVIDER_UNAVAILABLE',
            __('payments::messages.provider_unknown', ['provider' => $name]),
            503,
        );
    }

    public function hasAny(): bool
    {
        return $this->all() !== [];
    }

    /**
     * @return list<PaymentProvider>
     */
    public function all(): array
    {
        $providers = array_map(
            fn (string $class) => $this->container->make($class),
            $this->providers,
        );

        return array_values(array_filter(
            $providers,
            static fn (PaymentProvider $provider) => $provider->isConfigured(),
        ));
    }
}
