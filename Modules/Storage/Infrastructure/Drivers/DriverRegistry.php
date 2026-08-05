<?php

declare(strict_types=1);

namespace Modules\Storage\Infrastructure\Drivers;

use App\Platform\Exceptions\DomainException;
use Illuminate\Contracts\Container\Container;
use Modules\Storage\Domain\Models\Destination;

/**
 * `driver` → pilote.
 *
 * Un pilote absent échoue durement. Le repli silencieux est la faute que
 * Payments a déjà refusée pour ses agrégateurs : il ferait aboutir une écriture
 * que personne ne saurait rattacher — et ici, dans un magasin qu'on ne saurait
 * pas relire.
 */
final class DriverRegistry
{
    /** @var array<string, class-string<StorageDriver>> */
    private array $drivers = [];

    /** @var array<string, StorageDriver> */
    private array $resolved = [];

    public function __construct(private readonly Container $container) {}

    /**
     * @param  class-string<StorageDriver>  $driver
     */
    public function register(string $name, string $driver): void
    {
        $this->drivers[$name] = $driver;
    }

    public function for(Destination $destination): StorageDriver
    {
        return $this->get((string) $destination->driver);
    }

    public function get(string $name): StorageDriver
    {
        if (! isset($this->drivers[$name])) {
            throw DomainException::unprocessable(
                'STORAGE_DRIVER_UNKNOWN',
                "Aucun pilote de stockage nommé « {$name} » n'est enregistré.",
            );
        }

        return $this->resolved[$name] ??= $this->container->make($this->drivers[$name]);
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->drivers);
    }
}
