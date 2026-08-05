<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Storage\Presentation\Http\Controllers\LocalObjectController;

/*
|--------------------------------------------------------------------------
| Le magasin local
|--------------------------------------------------------------------------
|
| **Hors du contrat d'API, et c'est délibéré.** Ces deux routes ne sont pas une
| API : elles sont un magasin d'objets, l'équivalent local de `s3.amazonaws.com`.
| Les versionner et les documenter reviendrait à publier l'infrastructure de nos
| tests comme si c'était une promesse faite aux clients.
|
| Elles vivent donc à la racine du domaine, comme `/.well-known/…` — hors du
| préfixe de version, hors du contrat OpenAPI.
|
| L'accès repose entièrement sur la signature de l'URL, hors de la pile
| d'authentification : c'est exactement le fonctionnement d'une URL présignée
| S3, une capacité valable pour un objet et une durée, et non un droit attaché à
| qui la présente.
|
| Le préfixe `object-store` évite la route attrape-tout `storage/{path}` que
| Laravel enregistre lui-même pour ses disques locaux — sans quoi l'une
| avalerait l'autre selon l'ordre d'enregistrement, silencieusement.
|
| En production elles ne servent jamais : aucune destination `local` n'y est
| éligible, l'environnement d'une destination devant correspondre à celui de
| l'application.
|
| @see docs/03-services/storage/06-destinations.md
|
*/

Route::middleware('signed')->group(function (): void {
    Route::put('object-store/{destination}/{path}', [LocalObjectController::class, 'put'])
        ->name('local-upload');
    Route::get('object-store/{destination}/{path}', [LocalObjectController::class, 'get'])
        ->name('local-download');
});
