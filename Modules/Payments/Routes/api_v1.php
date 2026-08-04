<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Payments\Presentation\Http\Controllers\ChargeController;
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
| **Aucune route de création pour un module du monolithe.** Déclencher un
| paiement suppose de savoir ce qu'on paie, combien cela vaut et qui a le droit
| de le régler. C'est le propriétaire de l'objet payé qui expose sa propre
| route : `POST /payments` côté Billing règle une facture.
|
| `POST /payments/charges` est l'exception, et elle n'en est pas une : elle sert
| les produits qui ne partagent pas cette base de code et ne peuvent donc
| implémenter aucune interface. La règle n'a jamais été « aucune création ici »,
| elle est **seul le propriétaire de l'objet nomme son prix** — prouvé par une
| interface pour un module, par une clé scopée pour un service externe.
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

/*
| Encaissement pour un service externe.
|
| Authentifiée par **clé d'API**, jamais par un access token : une clé agit au
| nom d'un produit, pas d'une personne, et il n'existe aucun utilisateur Sekuu
| derrière un apprenant Learn.
|
| Le scope est vérifié dans le contrôleur, avec le périmètre de `subject_type`
| que la clé porte — les deux vont ensemble, et les séparer laisserait passer
| une clé habilitée à encaisser sans dire sur quoi.
|
| @see docs/03-services/payments/07-external-api.md
*/
Route::post('payments/charges', [ChargeController::class, 'store'])->name('charges.store');
Route::get('payments/charges', [ChargeController::class, 'index'])->name('charges.index');
Route::get('payments/charges/{charge}', [ChargeController::class, 'show'])->name('charges.show');

Route::middleware(['auth:api', 'organization'])->group(function (): void {
    Route::get('payments', [PaymentController::class, 'index'])->name('payments.index');
    Route::get('payments/{payment}', [PaymentController::class, 'show'])->name('payments.show');
});
