<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Storage\Presentation\Http\Controllers\DestinationController;
use Modules\Storage\Presentation\Http\Controllers\FileController;

/*
|--------------------------------------------------------------------------
| Storage — API v1
|--------------------------------------------------------------------------
|
| Préfixe appliqué par le provider : storage.sekuu.com/api/v1
|
| **Aucune route ne rend d'octet.** La plateforme ne sert jamais un fichier
| elle-même : elle délivre des URL signées vers l'hôte du magasin. Servir les
| octets ici ferait de la plateforme le mandataire de son propre magasin —
| mémoire, délai de requête, et un fichier client servi depuis notre domaine.
|
| @see docs/03-services/storage/03-api.md
|
*/

Route::middleware(['auth:api'])->group(function (): void {
    Route::post('files', [FileController::class, 'store'])->name('files.store');
    Route::post('files/{file}/confirm', [FileController::class, 'confirm'])->name('files.confirm');
    Route::get('files', [FileController::class, 'index'])->name('files.index');
    Route::get('files/{file}', [FileController::class, 'show'])->name('files.show');
    Route::get('files/{file}/url', [FileController::class, 'url'])->name('files.url');
    Route::delete('files/{file}', [FileController::class, 'destroy'])->name('files.destroy');

    /*
    | Administration des magasins.
    |
    | Pas de `DELETE` : une destination qui porte des fichiers ne se supprime
    | pas — la ligne disparue, la base serait la seule à savoir où les octets
    | étaient. On la passe `read_only` : on cesse d'y écrire, on continue d'y
    | lire.
    */
    Route::get('storage/destinations', [DestinationController::class, 'index'])->name('destinations.index');
    Route::post('storage/destinations', [DestinationController::class, 'store'])->name('destinations.store');
    Route::post('storage/destinations/{destination}/verify', [DestinationController::class, 'verify'])->name('destinations.verify');
    Route::put('storage/destinations/{destination}/credentials', [DestinationController::class, 'credentials'])->name('destinations.credentials');
    Route::patch('storage/destinations/{destination}', [DestinationController::class, 'update'])->name('destinations.update');
});
