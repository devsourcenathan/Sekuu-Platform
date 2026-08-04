<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Sous-domaines des modules
    |--------------------------------------------------------------------------
    |
    | Chaque domaine de la plateforme est exposé via son propre sous-domaine,
    | même si une seule application est déployée. Laisser une valeur vide
    | désactive la contrainte d'hôte : les routes du module répondent alors
    | sur n'importe quel domaine, ce qui est le comportement attendu en local.
    |
    | @see docs/01-overview/architecture.md
    |
    */

    'domains' => [
        'identity' => env('SEKUU_DOMAIN_IDENTITY'),
        'verify' => env('SEKUU_DOMAIN_VERIFY'),
        'notify' => env('SEKUU_DOMAIN_NOTIFY'),
        'billing' => env('SEKUU_DOMAIN_BILLING'),
        'storage' => env('SEKUU_DOMAIN_STORAGE'),
        'ai' => env('SEKUU_DOMAIN_AI'),
        'search' => env('SEKUU_DOMAIN_SEARCH'),
        'analytics' => env('SEKUU_DOMAIN_ANALYTICS'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Langues supportées
    |--------------------------------------------------------------------------
    |
    | Toute langue absente de cette liste est ignorée, y compris si elle est
    | demandée par `Accept-Language` ou enregistrée dans un profil : mieux vaut
    | répondre dans la langue par défaut qu'exposer des clés de traduction
    | brutes.
    |
    | @see app/Platform/Http/Middleware/ResolveLocale.php
    |
    */

    'locales' => ['en', 'fr'],

    /*
    |--------------------------------------------------------------------------
    | Pagination
    |--------------------------------------------------------------------------
    |
    | Toutes les listes sont paginées. `per_page` est plafonné pour empêcher
    | qu'un client ne demande une collection entière.
    |
    */

    'pagination' => [
        'per_page' => 20,
        'max_per_page' => 100,
    ],

    /*
    |--------------------------------------------------------------------------
    | Devises
    |--------------------------------------------------------------------------
    |
    | L'exposant est le nombre de décimales. **Le franc CFA n'en a aucune** :
    | 1 000 XAF se stocke `1000`, pas `100000`. Appliquer le réflexe « ×100 »
    | multiplierait tous les montants par cent, et l'erreur est invisible en
    | développement où les montants sont inventés.
    |
    | Ici et non dans un module : la facturation et le paiement lisent la même
    | table. Deux définitions de l'exposant, c'est deux vérités sur un montant.
    |
    | @see app/Platform/Support/Money.php
    |
    */

    'currencies' => [
        'XAF' => ['exponent' => 0],
        'XOF' => ['exponent' => 0],
        'EUR' => ['exponent' => 2],
        'USD' => ['exponent' => 2],
    ],

    'default_currency' => env('SEKUU_CURRENCY', 'XAF'),

];
