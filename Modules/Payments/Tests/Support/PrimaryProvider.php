<?php

declare(strict_types=1);

namespace Modules\Payments\Tests\Support;

final class PrimaryProvider extends FakeProvider
{
    public function name(): string
    {
        return 'primary';
    }
}
