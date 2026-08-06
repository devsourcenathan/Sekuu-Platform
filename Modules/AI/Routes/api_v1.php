<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\AI\Presentation\Http\Controllers\AccountController;
use Modules\AI\Presentation\Http\Controllers\HealthController;
use Modules\AI\Presentation\Http\Controllers\TaskController;
use Modules\AI\Presentation\Http\Controllers\UsageController;

/*
|--------------------------------------------------------------------------
| AI — API v1
|--------------------------------------------------------------------------
|
| Préfixe appliqué par le provider : ai.sekuu.com/api/v1
|
| **Aucune route ne porte de champ `model`.** C'est l'invariant du module :
| seule la plateforme nomme le modèle. Une route qui l'accepterait rouvrirait
| ce que l'ADR-0015 ferme, avec en prime une facture imprévisible.
|
| @see docs/03-services/ai/03-api.md
| @see docs/04-decisions/adr-0015-ai-task-not-model.md
|
*/

/*
| Pas de garde de route.
|
| Les deux schémas d'authentification — access token et clé d'API — partagent
| l'en-tête `Authorization`, et `auth:api` ne connaît que le premier : il
| rejetterait une clé de produit avant qu'elle n'atteigne le contrôleur. C'est
| le choix déjà fait côté Payments et Storage.
|
| L'authentification et le scope sont résolus dans le contrôleur, par
| `ResolvesAiActor`.
*/

/*
| Les comptes, et lesquels servent.
|
| Publique, comme `storage/health` : sur une offre sans shell, c'est le seul
| moyen de savoir qu'un compte est tombé avant qu'un client ne le découvre.
| Elle ne dit ni l'empreinte de la clé, ni le point d'accès, ni le message brut
| du fournisseur.
*/
Route::get('ai/health', HealthController::class)->name('health');

Route::get('ai/tasks', [TaskController::class, 'index'])->name('tasks.index');
Route::post('ai/tasks', [TaskController::class, 'store'])->name('tasks.store');
Route::get('ai/tasks/{generation}', [TaskController::class, 'show'])->name('tasks.show');
Route::post('ai/tasks/{generation}/cancel', [TaskController::class, 'cancel'])->name('tasks.cancel');

Route::get('ai/usage', UsageController::class)->name('usage');

/*
| Administration des comptes **du client**.
|
| Pas de `DELETE` : un compte qui porte des générations ne se supprime pas —
| le registre dit qui a payé quoi, et la ligne disparue, il ne le dirait plus.
| On met en pause.
|
| Aucune de ces routes ne touche un compte de la plateforme : il porte nos
| identifiants et sert toutes les organisations. Il se pose par `ai:account`.
*/
Route::get('ai/accounts', [AccountController::class, 'index'])->name('accounts.index');
Route::post('ai/accounts', [AccountController::class, 'store'])->name('accounts.store');
Route::post('ai/accounts/{account}/verify', [AccountController::class, 'verify'])->name('accounts.verify');
Route::put('ai/accounts/{account}/credentials', [AccountController::class, 'credentials'])->name('accounts.credentials');
Route::patch('ai/accounts/{account}', [AccountController::class, 'update'])->name('accounts.update');
