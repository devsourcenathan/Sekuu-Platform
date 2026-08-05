<?php

declare(strict_types=1);

namespace Modules\AI\Infrastructure\Drivers;

/**
 * Ce qu'un protocole sait faire.
 */
final readonly class DriverCapabilities
{
    public function __construct(
        public bool $json,
        public bool $tools,
        public bool $history,
    ) {}
}
