<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Billing\Presentation\Http\Controllers\InvoiceController;
use Modules\Billing\Presentation\Http\Controllers\InvoicePaymentController;
use Modules\Billing\Presentation\Http\Controllers\PlanController;
use Modules\Billing\Presentation\Http\Controllers\PlatformOrganizationController;
use Modules\Billing\Presentation\Http\Controllers\PlatformPlanController;
use Modules\Billing\Presentation\Http\Controllers\SubscriptionController;

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

/*
| Catalogue. Public : une page de tarifs doit être lisible avant d'avoir un
| compte. Il n'y a pas de POST — les plans sont versionnés avec le code.
*/
Route::get('plans', [PlanController::class, 'index'])->name('plans.index');
Route::get('plans/{plan}', [PlanController::class, 'show'])->name('plans.show');

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

    /*
    | Régler une facture.
    |
    | La **consultation** des paiements appartient à Payments ; leur
    | déclenchement reste ici, parce qu'il suppose de savoir ce qu'on paie,
    | combien cela vaut, et qui a le droit de le régler.
    */
    Route::post('payments', InvoicePaymentController::class)->name('payments.store');
});

/*
|--------------------------------------------------------------------------
| Administration de la plateforme
|--------------------------------------------------------------------------
|
| Hors du périmètre client : ces routes ne s'adressent pas à une organisation
| mais à **Sekuu**. Le préfixe est délibérément visible — dans une table de
| routage et dans un journal, on doit voir ce qui relève de l'exploitation
| plutôt que le deviner derrière une condition.
|
| `platform:<permission>` vérifie l'habilitation **et journalise l'appel**, y
| compris les lectures : consulter la facture d'un client, c'est accéder à une
| donnée qui ne nous appartient pas.
|
| L'habilitation ne s'obtient que par `identity:operator`, jamais par une route.
|
| @see docs/04-decisions/adr-0018-platform-operator.md
|
*/
Route::prefix('platform')->name('platform.')->group(function (): void {
    Route::middleware('platform:platform.plans')->group(function (): void {
        Route::get('plans', [PlatformPlanController::class, 'index'])->name('plans.index');
        Route::patch('plans/{plan}', [PlatformPlanController::class, 'update'])->name('plans.update');
    });

    Route::middleware('platform:platform.organizations')->group(function (): void {
        Route::get('organizations', [PlatformOrganizationController::class, 'index'])->name('organizations.index');
        Route::get('organizations/{organization}', [PlatformOrganizationController::class, 'show'])->name('organizations.show');
    });

    /*
    | Les factures relèvent d'une permission **distincte** : consulter un
    | montant qu'un client a payé n'est pas la même chose que constater son
    | existence.
    */
    Route::middleware('platform:platform.billing')->group(function (): void {
        Route::get('organizations/{organization}/invoices', [PlatformOrganizationController::class, 'invoices'])
            ->name('organizations.invoices');
    });
});
