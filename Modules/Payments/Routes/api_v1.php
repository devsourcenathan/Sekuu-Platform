<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Payments\Presentation\Http\Controllers\HealthController;
use Modules\Payments\Presentation\Http\Controllers\PaymentController;
use Modules\Payments\Presentation\Http\Controllers\WebhookController;

/*
|--------------------------------------------------------------------------
| Payments — API v1
|--------------------------------------------------------------------------
|
| Préfixe appliqué par le provider : payments.sekuu.com/api/v1
|
| **Aucune route de création.** Déclencher un paiement suppose de savoir ce
| qu'on paie, combien cela vaut et qui a le droit de le régler — trois choses
| que ce module ignore. C'est le propriétaire de l'objet payé qui expose sa
| propre route : `POST /payments` côté Billing règle une facture.
|
| @see docs/03-services/payments/03-api.md
|
*/

Route::get('payments/health', HealthController::class)->name('health');

/*
| Callbacks des agrégateurs. Publique au sens réseau, authentifiée par
| signature ou secret partagé, et jamais crue sur parole : le statut est relu
| chez l'agrégateur.
|
| Préfixée comme la route de santé : en production les sous-domaines séparent
| les modules, mais en développement ils répondent tous sur le même hôte — et
| `webhooks/{provider}` existe déjà pour Notify. Sans ce préfixe, le dernier
| module enregistré écrase l'autre, silencieusement.
*/
Route::post('payments/webhooks/{provider}', WebhookController::class)->name('webhooks');

/*
| Ancienne adresse, conservée le temps que les tableaux de bord des agrégateurs
| soient mis à jour. Des transactions déjà initialisées portent cette URL figée
| dans leur payload : la retirer trop tôt ferait échouer leurs callbacks.
*/
Route::post('billing/webhooks/{provider}', WebhookController::class)->name('webhooks.legacy');

Route::middleware(['auth:api', 'organization'])->group(function (): void {
    Route::get('payments', [PaymentController::class, 'index'])->name('payments.index');
    Route::get('payments/{payment}', [PaymentController::class, 'show'])->name('payments.show');
});
