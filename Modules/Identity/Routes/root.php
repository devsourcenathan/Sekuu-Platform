<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Identity\Presentation\Http\Controllers\JwksController;

/*
|--------------------------------------------------------------------------
| Identity — routes servies à la racine du domaine
|--------------------------------------------------------------------------
|
| Ces chemins sont normalisés : ils ne peuvent pas porter de préfixe de
| version.
|
*/

Route::get('.well-known/jwks.json', JwksController::class)->name('jwks');
