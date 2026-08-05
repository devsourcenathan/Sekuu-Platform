<?php

declare(strict_types=1);

namespace Modules\Storage\Application\Files;

use Illuminate\Support\Carbon;

final readonly class IssuedUrl
{
    public function __construct(
        public string $url,
        public Carbon $expiresAt,
        public string $disposition,
    ) {}
}
