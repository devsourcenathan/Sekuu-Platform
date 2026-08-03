<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Identity\Presentation\Http\Controllers\HealthController;

/*
|--------------------------------------------------------------------------
| Identity — API v1
|--------------------------------------------------------------------------
|
| Préfixe appliqué par le provider : identity.sekuu.com/api/v1
|
| L'inventaire complet des endpoints est spécifié dans
| docs/03-services/identity/03-api.md
|
*/

Route::get('health', HealthController::class)->name('health');
