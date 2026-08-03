<?php

declare(strict_types=1);

namespace Modules\Notify\Application\Sending;

final readonly class FilterVerdict
{
    private function __construct(
        public bool $allowed,
        public ?string $errorCode = null,
        public ?string $reason = null,
    ) {}

    public static function allowed(): self
    {
        return new self(true);
    }

    public static function blocked(string $errorCode, string $reason): self
    {
        return new self(false, $errorCode, $reason);
    }
}
