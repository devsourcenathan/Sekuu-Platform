<?php

declare(strict_types=1);

use Modules\Notify\Domain\Channel;
use Modules\Notify\Infrastructure\Providers\LaravelMailProvider;

/*
| Configuration du module Notify.
|
| @see docs/03-services/notify/01-overview.md
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Fournisseurs par canal
    |--------------------------------------------------------------------------
    |
    | L'ordre vaut priorité : le premier est essayé, les suivants servent de
    | bascule en cas d'échec **infrastructurel**. Un rejet métier — numéro
    | invalide, contenu refusé — n'entraîne aucune bascule : il ne réussira pas
    | davantage ailleurs, et chaque tentative coûte.
    |
    */

    'providers' => [
        Channel::EMAIL => [
            LaravelMailProvider::class,
        ],

        // SMS, WhatsApp et push seront ajoutés ici. Sur les marchés visés, un
        // opérateur local est un fournisseur de premier rang, pas un cas
        // particulier : mieux acheminé et moins cher qu'un envoi international.
        Channel::SMS => [],
        Channel::WHATSAPP => [],
        Channel::PUSH => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Rétention
    |--------------------------------------------------------------------------
    |
    | Les suppressions ne sont jamais purgées : une adresse qui rebondit
    | durablement ne redevient pas valide avec le temps.
    |
    */

    'retention' => [
        'notifications_days' => 365,
    ],

    'queue' => env('NOTIFY_QUEUE', 'notifications'),

];
