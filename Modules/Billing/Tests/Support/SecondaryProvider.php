<?php

declare(strict_types=1);

namespace Modules\Billing\Tests\Support;

final class SecondaryProvider extends FakeProvider
{
    public function name(): string
    {
        return 'secondary';
    }
}
