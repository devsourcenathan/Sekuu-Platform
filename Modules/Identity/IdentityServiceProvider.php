<?php

declare(strict_types=1);

namespace Modules\Identity;

use App\Platform\Support\ModuleServiceProvider;

final class IdentityServiceProvider extends ModuleServiceProvider
{
    protected function moduleSlug(): string
    {
        return 'identity';
    }

    protected function modulePath(): string
    {
        return __DIR__;
    }
}
