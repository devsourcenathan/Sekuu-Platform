<?php

use App\Providers\AppServiceProvider;
use Modules\Billing\BillingServiceProvider;
use Modules\Identity\IdentityServiceProvider;
use Modules\Notify\NotifyServiceProvider;
use Modules\Payments\PaymentsServiceProvider;
use Modules\Storage\StorageServiceProvider;

return [
    AppServiceProvider::class,

    // Modules de la plateforme.
    IdentityServiceProvider::class,
    NotifyServiceProvider::class,

    // Storage avant Billing, pour la même raison que Payments : Billing lui
    // confie le PDF de ses factures et s'enregistre dans son registre de
    // propriétaires. Storage ne connaît Billing que par configuration.
    StorageServiceProvider::class,

    // Payments avant Billing : Billing enregistre un objet payable dans le
    // registre de Payments, qui doit donc exister. L'inverse n'est pas vrai —
    // Payments ne connaît Billing que par configuration.
    PaymentsServiceProvider::class,
    BillingServiceProvider::class,
];
