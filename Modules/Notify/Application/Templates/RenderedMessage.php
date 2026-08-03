<?php

declare(strict_types=1);

namespace Modules\Notify\Application\Templates;

final readonly class RenderedMessage
{
    public function __construct(
        public string $locale,
        public ?string $subject,
        public string $body,
    ) {}
}
