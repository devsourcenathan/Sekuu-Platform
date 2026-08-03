<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Identity\Presentation\Http\Controllers\AuthController;
use Modules\Identity\Presentation\Http\Controllers\HealthController;
use Modules\Identity\Presentation\Http\Controllers\InvitationController;
use Modules\Identity\Presentation\Http\Controllers\OrganizationController;
use Modules\Identity\Presentation\Http\Controllers\WorkspaceController;
use Modules\Identity\Presentation\Http\Controllers\WorkspaceMemberController;

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

/*
| Routes exigeant une organisation active dans le token. L'organisation n'est
| jamais lue depuis l'URL pour déterminer la portée : lorsqu'elle y figure,
| elle doit correspondre à celle du token.
*/
Route::middleware(['auth:api', 'organization'])->group(function (): void {
    Route::prefix('workspaces')->name('workspaces.')->group(function (): void {
        Route::get('/', [WorkspaceController::class, 'index'])->name('index');
        Route::post('/', [WorkspaceController::class, 'store'])
            ->middleware('scope:workspace.create')->name('store');

        Route::get('{workspace}', [WorkspaceController::class, 'show'])->name('show');
        Route::patch('{workspace}', [WorkspaceController::class, 'update'])
            ->middleware('scope:workspace.manage')->name('update');
        Route::delete('{workspace}', [WorkspaceController::class, 'destroy'])
            ->middleware('scope:workspace.manage')->name('destroy');

        Route::get('{workspace}/members', [WorkspaceMemberController::class, 'index'])->name('members.index');
        Route::post('{workspace}/members', [WorkspaceMemberController::class, 'store'])
            ->middleware('scope:workspace.manage')->name('members.store');
        Route::delete('{workspace}/members/{membership}', [WorkspaceMemberController::class, 'destroy'])
            ->middleware('scope:workspace.manage')->name('members.destroy');
    });

    Route::get('organizations/{organization}/invitations', [InvitationController::class, 'index'])
        ->name('invitations.index');
    Route::post('organizations/{organization}/invitations', [InvitationController::class, 'store'])
        ->middleware('scope:users.invite')->name('invitations.store');
    Route::delete('invitations/{invitation}', [InvitationController::class, 'destroy'])
        ->middleware('scope:users.invite')->name('invitations.destroy');
});

/*
| Acceptation d'invitation : routes publiques, le jeton fait office de preuve.
| Un utilisateur déjà connecté est pris en compte s'il présente un token.
*/
Route::middleware('throttle:10,1')->group(function (): void {
    Route::get('invitations/{token}', [InvitationController::class, 'show'])
        ->name('invitations.show');
    Route::post('invitations/{token}/accept', [InvitationController::class, 'accept'])
        ->name('invitations.accept');
});
