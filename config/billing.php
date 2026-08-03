<?php

declare(strict_types=1);

use Modules\Billing\Infrastructure\Providers\TranzakProvider;
use Modules\Billing\Infrastructure\Webhooks\TranzakWebhookHandler;

/*
| Configuration du module Billing.
|
| @see docs/03-services/billing/01-overview.md
| @see docs/04-decisions/adr-0008-payment-aggregators-failover.md
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Agrégateurs de paiement
    |--------------------------------------------------------------------------
    |
    | L'ordre vaut priorité. Un agrégateur non configuré n'est jamais essayé :
    | en développement, aucun paiement ne part, et le module le dit franchement
    | plutôt que d'échouer à l'exécution.
    |
    | La bascule est **volontairement étroite** : on ne réessaie chez le suivant
    | que si l'invite n'est jamais partie sur le téléphone du client. Tout le
    | reste produirait un double débit.
    |
    | Notch Pay et Tara viendront s'ajouter ici. Tara n'a aujourd'hui aucune
    | documentation technique publique — voir docs/03-services/billing/05-providers.md.
    |
    */

    'providers' => [
        TranzakProvider::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Retours de paiement
    |--------------------------------------------------------------------------
    |
    | Sans ces callbacks, un paiement n'est constaté que par le sondage — plus
    | lent, mais jamais absent. C'est l'inverse de Notify, où le webhook est la
    | seule source : ici l'argent interdit d'en dépendre.
    |
    */

    'webhooks' => [
        'tranzak' => TranzakWebhookHandler::class,
    ],

    'tranzak' => [
        'base_url' => env('TRANZAK_BASE_URL', 'https://sandbox.dsapi.tranzak.me'),
        'app_id' => env('TRANZAK_APP_ID'),
        'app_key' => env('TRANZAK_APP_KEY'),

        // Le `authKey` des callbacks est un secret partagé transporté dans le
        // corps, pas une signature : il prouve que l'émetteur connaît le
        // secret, jamais que le corps est intact. D'où la règle qui suit.
        'auth_key' => env('TRANZAK_AUTH_KEY'),

        'timeout' => 20,
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
    */

    'currencies' => [
        'XAF' => ['exponent' => 0],
        'XOF' => ['exponent' => 0],
        'EUR' => ['exponent' => 2],
        'USD' => ['exponent' => 2],
    ],

    'default_currency' => env('BILLING_CURRENCY', 'XAF'),

    /*
    |--------------------------------------------------------------------------
    | Taxes
    |--------------------------------------------------------------------------
    |
    | Le taux est appliqué à l'émission et **figé** sur la facture. Une facture
    | est un document légal : la recalculer plus tard à partir du taux courant
    | produirait un document qui ne correspond plus à ce qui a été payé.
    |
    | Cameroun : TVA 18 % + centimes additionnels communaux = 19,25 %.
    |
    */

    'tax' => [
        'CM' => 0.1925,
    ],

    'default_country' => env('BILLING_COUNTRY', 'CM'),

    /*
    |--------------------------------------------------------------------------
    | Cycle de vie
    |--------------------------------------------------------------------------
    |
    | La grâce coûte quelques jours de service non payé. Sans elle, un oubli
    | d'une journée devient une interruption d'activité : une clinique qui ne
    | peut pas ouvrir son agenda un lundi matin ne reste pas cliente.
    |
    */

    'grace_days' => 7,

    'expire_after_days' => 90,

    'reminder_days' => [7, 3, 1],

    /*
    |--------------------------------------------------------------------------
    | Paiement
    |--------------------------------------------------------------------------
    |
    | `intent_ttl_minutes` borne l'attente d'une invite. Au-delà, l'intention
    | devient `expired` — ce qui signifie **on ne sait pas**, et non « cela a
    | échoué ». La différence commande le rapprochement manuel, et interdit
    | toute nouvelle tentative automatique.
    |
    */

    'payment' => [
        'intent_ttl_minutes' => 10,
        'poll_backoff_seconds' => [5, 10, 20, 30, 60, 120],
    ],

    /*
    |--------------------------------------------------------------------------
    | Réseaux mobiles du Cameroun
    |--------------------------------------------------------------------------
    |
    | Le réseau du payeur est un **fait** déduit du numéro, pas un choix. Il
    | détermine quels agrégateurs peuvent servir, jamais lequel est essayé en
    | premier — cet ordre-là est celui de `providers`.
    |
    */

    'operators' => [
        'CM' => [
            'country_code' => '237',
            'prefixes' => [
                'mtn' => ['67', '68', '650', '651', '652', '653', '654'],
                'orange' => ['69', '655', '656', '657', '658', '659'],
            ],
        ],
    ],

    'queue' => env('BILLING_QUEUE', 'billing'),

];
