<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Notify\Presentation\Http\Controllers\HealthController;
use Modules\Notify\Presentation\Http\Controllers\InboxController;
use Modules\Notify\Presentation\Http\Controllers\NotificationController;
use Modules\Notify\Presentation\Http\Controllers\PreferenceController;
use Modules\Notify\Presentation\Http\Controllers\SendController;
use Modules\Notify\Presentation\Http\Controllers\WebhookController;

/*
|--------------------------------------------------------------------------
| Notify — API v1
|--------------------------------------------------------------------------
|
| Préfixe appliqué par le provider : notify.sekuu.com/api/v1
|
| @see docs/03-services/notify/03-api.md
|
*/

Route::get('notify/health', HealthController::class)->name('health');

/*
| Retours de livraison. Publique au sens réseau, authentifiée par signature :
| chaque fournisseur signe selon son propre schéma, vérifié sur le corps brut.
*/
Route::post('webhooks/{provider}', WebhookController::class)->name('webhooks');

/*
| Déclenchement d'un envoi. Jamais une action d'utilisateur final : une clé
| d'API portant `notifications.send` est exigée, sinon n'importe quel
| utilisateur connecté pourrait écrire au nom de la plateforme.
*/
Route::middleware('api-key:notifications.send')->group(function (): void {
    Route::post('notifications', [SendController::class, 'store'])->name('notifications.store');
    Route::post('notifications/bulk', [SendController::class, 'bulk'])->name('notifications.bulk');
    Route::post('notifications/{notification}/cancel', [SendController::class, 'cancel'])
        ->name('notifications.cancel');
});

/*
| Désabonnement. Public : exiger une connexion pour se désabonner est une
| pratique hostile, et pousse vers le bouton « spam ».
*/
Route::middleware('throttle:20,1')->group(function (): void {
    Route::get('preferences/unsubscribe/{token}', [PreferenceController::class, 'showUnsubscribe'])
        ->name('preferences.unsubscribe.show');
    Route::post('preferences/unsubscribe/{token}', [PreferenceController::class, 'unsubscribe'])
        ->name('preferences.unsubscribe');
});

Route::middleware('auth:api')->group(function (): void {
    Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::get('notifications/{notification}', [NotificationController::class, 'show'])
        ->name('notifications.show');

    // Les préférences appartiennent à l'utilisateur : elles ne dépendent
    // d'aucune organisation active.
    Route::get('preferences', [PreferenceController::class, 'index'])->name('preferences.index');
    Route::patch('preferences', [PreferenceController::class, 'update'])->name('preferences.update');

    // Canal interne : aucun fournisseur externe, donc toujours disponible.
    Route::get('inbox', [InboxController::class, 'index'])->name('inbox.index');
    Route::get('inbox/unread-count', [InboxController::class, 'unreadCount'])->name('inbox.unread-count');
    Route::post('inbox/read-all', [InboxController::class, 'markAllAsRead'])->name('inbox.read-all');
    Route::post('inbox/{notification}/read', [InboxController::class, 'markAsRead'])->name('inbox.read');
});
