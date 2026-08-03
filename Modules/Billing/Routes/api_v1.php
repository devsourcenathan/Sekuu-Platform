<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Billing\Presentation\Http\Controllers\HealthController;
use Modules\Billing\Presentation\Http\Controllers\InvoiceController;
use Modules\Billing\Presentation\Http\Controllers\PaymentController;
use Modules\Billing\Presentation\Http\Controllers\PlanController;
use Modules\Billing\Presentation\Http\Controllers\SubscriptionController;
use Modules\Billing\Presentation\Http\Controllers\WebhookController;

/*
|--------------------------------------------------------------------------
| Billing — API v1
|--------------------------------------------------------------------------
|
| Préfixe appliqué par le provider : billing.sekuu.com/api/v1
|
| Cette API ne répond **jamais** à « ai-je accès à ce produit ? ». Cette
| question se pose à Identity, sur `organization_products`. Y répondre ici
| créerait une seconde vérité, et placerait Billing sur le chemin critique de
| chaque requête produit.
|
| @see docs/03-services/billing/03-api.md
|
*/

Route::get('billing/health', HealthController::class)->name('health');

/*
| Catalogue. Public : une page de tarifs doit être lisible avant d'avoir un
| compte. Il n'y a pas de POST — les plans sont versionnés avec le code.
*/
Route::get('plans', [PlanController::class, 'index'])->name('plans.index');
Route::get('plans/{plan}', [PlanController::class, 'show'])->name('plans.show');

/*
| Callbacks des agrégateurs. Publique au sens réseau, authentifiée par
| signature ou secret partagé, et jamais crue sur parole : le statut est relu
| chez l'agrégateur.
|
| Préfixée par `billing/` comme la route de santé : en production les
| sous-domaines séparent les modules, mais en développement ils répondent tous
| sur le même hôte — et `webhooks/{provider}` existe déjà pour Notify. Sans ce
| préfixe, le dernier module enregistré écrase l'autre, silencieusement.
*/
Route::post('billing/webhooks/{provider}', WebhookController::class)->name('webhooks');

Route::middleware(['auth:api', 'organization'])->group(function (): void {
    /*
    | Le singulier est délibéré : une organisation n'a qu'un abonnement vivant.
    | Consulter est ouvert à tout membre ; engager une dépense exige Owner ou
    | Admin, vérifié dans les contrôleurs.
    */
    Route::get('subscription', [SubscriptionController::class, 'show'])->name('subscription.show');
    Route::get('subscription/history', [SubscriptionController::class, 'history'])
        ->name('subscription.history');
    Route::post('subscription', [SubscriptionController::class, 'store'])->name('subscription.store');
    Route::post('subscription/change', [SubscriptionController::class, 'change'])
        ->name('subscription.change');
    Route::post('subscription/renew', [SubscriptionController::class, 'renew'])
        ->name('subscription.renew');
    Route::post('subscription/cancel', [SubscriptionController::class, 'cancel'])
        ->name('subscription.cancel');
    Route::post('subscription/resume', [SubscriptionController::class, 'resume'])
        ->name('subscription.resume');

    Route::get('invoices', [InvoiceController::class, 'index'])->name('invoices.index');
    Route::get('invoices/{invoice}', [InvoiceController::class, 'show'])->name('invoices.show');
    Route::get('invoices/{invoice}/download', [InvoiceController::class, 'download'])
        ->name('invoices.download');

    Route::get('payments', [PaymentController::class, 'index'])->name('payments.index');
    Route::post('payments', [PaymentController::class, 'store'])->name('payments.store');
    Route::get('payments/{payment}', [PaymentController::class, 'show'])->name('payments.show');
});
