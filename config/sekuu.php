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

];
