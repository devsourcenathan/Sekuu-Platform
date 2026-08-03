<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Notify\Presentation\Http\Controllers\HealthController;
use Modules\Notify\Presentation\Http\Controllers\NotificationController;
use Modules\Notify\Presentation\Http\Controllers\PreferenceController;

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

Route::middleware('auth:api')->group(function (): void {
    Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::get('notifications/{notification}', [NotificationController::class, 'show'])
        ->name('notifications.show');

    // Les préférences appartiennent à l'utilisateur : elles ne dépendent
    // d'aucune organisation active.
    Route::get('preferences', [PreferenceController::class, 'index'])->name('preferences.index');
    Route::patch('preferences', [PreferenceController::class, 'update'])->name('preferences.update');
});
