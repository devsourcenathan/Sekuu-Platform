<?php

declare(strict_types=1);

namespace Modules\Payments\Tests\Support;

final class SecondaryProvider extends FakeProvider
{
    public function name(): string
    {
        return 'secondary';
    }
}
