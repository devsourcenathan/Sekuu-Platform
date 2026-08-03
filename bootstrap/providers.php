<?php

use App\Providers\AppServiceProvider;
use Modules\Billing\BillingServiceProvider;
use Modules\Identity\IdentityServiceProvider;
use Modules\Notify\NotifyServiceProvider;

return [
    AppServiceProvider::class,

    // Modules de la plateforme.
    IdentityServiceProvider::class,
    NotifyServiceProvider::class,
    BillingServiceProvider::class,
];
