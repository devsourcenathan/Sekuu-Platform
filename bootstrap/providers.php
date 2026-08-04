<?php

use App\Providers\AppServiceProvider;
use Modules\Billing\BillingServiceProvider;
use Modules\Identity\IdentityServiceProvider;
use Modules\Notify\NotifyServiceProvider;
use Modules\Payments\PaymentsServiceProvider;

return [
    AppServiceProvider::class,

    // Modules de la plateforme.
    IdentityServiceProvider::class,
    NotifyServiceProvider::class,

    // Payments avant Billing : Billing enregistre un objet payable dans le
    // registre de Payments, qui doit donc exister. L'inverse n'est pas vrai —
    // Payments ne connaît Billing que par configuration.
    PaymentsServiceProvider::class,
    BillingServiceProvider::class,
];
