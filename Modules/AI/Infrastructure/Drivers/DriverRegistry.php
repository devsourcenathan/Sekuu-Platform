<?php

declare(strict_types=1);

namespace Modules\AI\Infrastructure\Drivers;

use App\Platform\Exceptions\DomainException;
use Illuminate\Contracts\Container\Container;
use Modules\AI\Domain\Models\AiAccount;

/**
 * `driver` → pilote.
 *
 * Un pilote absent échoue durement : un repli silencieux ferait partir une
 * génération chez un fournisseur que personne n'a choisi, et la facture
 * arriverait sans qu'on sache d'où.
 */
final class DriverRegistry
{
    /** @var array<string, class-string<AiDriver>> */
    private array $drivers = [];

    /** @var array<string, AiDriver> */
    private array $resolved = [];

    public function __construct(private readonly Container $container) {}

    /**
     * @param  class-string<AiDriver>  $driver
     */
    public function register(string $name, string $driver): void
    {
        $this->drivers[$name] = $driver;
    }

    public function for(AiAccount $account): AiDriver
    {
        return $this->get((string) $account->driver);
    }

    public function get(string $name): AiDriver
    {
        if (! isset($this->drivers[$name])) {
            throw DomainException::unprocessable(
                'AI_DRIVER_UNKNOWN',
                __('ai::messages.driver_unknown', ['driver' => $name]),
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
