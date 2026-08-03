<?php

use App\Providers\AppServiceProvider;
use Modules\Identity\IdentityServiceProvider;

return [
    AppServiceProvider::class,

    // Modules de la plateforme.
    IdentityServiceProvider::class,
];
