<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Identity\Presentation\Http\Controllers\AuthController;
use Modules\Identity\Presentation\Http\Controllers\HealthController;
use Modules\Identity\Presentation\Http\Controllers\OrganizationController;

/*
|--------------------------------------------------------------------------
| Identity — API v1
|--------------------------------------------------------------------------
|
| Préfixe appliqué par le provider : identity.sekuu.com/api/v1
|
| @see docs/03-services/identity/03-api.md
|
*/

Route::get('health', HealthController::class)->name('health');

Route::prefix('auth')->name('auth.')->group(function (): void {
    // Les routes d'authentification sont limitées par IP : 10 tentatives par
    // minute, conformément aux guidelines.
    Route::middleware('throttle:10,1')->group(function (): void {
        Route::post('register', [AuthController::class, 'register'])->name('register');
        Route::post('login', [AuthController::class, 'login'])->name('login');
        Route::post('refresh', [AuthController::class, 'refresh'])->name('refresh');
    });

    Route::middleware('auth:api')->group(function (): void {
        Route::get('me', [AuthController::class, 'me'])->name('me');
        Route::post('logout', [AuthController::class, 'logout'])->name('logout');
        Route::post('logout-all', [AuthController::class, 'logoutAll'])->name('logout-all');
        Route::post('switch-organization', [AuthController::class, 'switchOrganization'])
            ->name('switch-organization');
    });
});

Route::middleware('auth:api')->group(function (): void {
    Route::get('organizations', [OrganizationController::class, 'index'])->name('organizations.index');
    Route::post('organizations', [OrganizationController::class, 'store'])->name('organizations.store');
});
