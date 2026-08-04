<?php

declare(strict_types=1);

use Modules\Billing\Application\Invoicing\InvoicePayable;
use Modules\Payments\Application\External\ExternalPayable;
use Modules\Payments\Infrastructure\Providers\NotchPayProvider;
use Modules\Payments\Infrastructure\Providers\TranzakProvider;
use Modules\Payments\Infrastructure\Webhooks\NotchPayWebhookHandler;
use Modules\Payments\Infrastructure\Webhooks\TranzakWebhookHandler;

/*
| Configuration du module Payments.
|
| @see docs/03-services/payments/01-overview.md
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
    | Tara viendra s'ajouter ici. Il n'a aujourd'hui aucune documentation
    | technique publique — voir docs/03-services/payments/05-providers.md.
    |
    */

    'providers' => [
        NotchPayProvider::class,
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
        'notchpay' => NotchPayWebhookHandler::class,
        'tranzak' => TranzakWebhookHandler::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Objets payables
    |--------------------------------------------------------------------------
    |
    | `subject_type` → module propriétaire. C'est **le seul endroit** où la
    | couche de paiement apprend qu'une facture existe : aucun de ses fichiers
    | n'importe Billing, et un test d'architecture le vérifie.
    |
    | Ce fichier est la racine de composition, pas du code de module : c'est sa
    | raison d'être de connaître les deux côtés.
    |
    | Un produit s'enregistre en ajoutant une ligne. Un type absent échoue
    | durement (`PAYABLE_TYPE_UNKNOWN`) : un repli silencieux ferait aboutir un
    | paiement que personne ne saurait rattacher.
    |
    */

    'payables' => [
        InvoicePayable::TYPE => InvoicePayable::class,

        /*
        | Types servis par l'API externe.
        |
        | Deux bornes indépendantes protègent l'invariant du prix, et il faut
        | les deux : ce tableau dit quels types un service **hors monolithe**
        | peut posséder, la clé d'API dit lesquels **ce produit-là** peut faire
        | payer. Une clé mal émise ne suffit donc pas à faire déclarer un prix
        | sur une facture d'abonnement, et une ligne ajoutée ici n'habilite
        | personne tant qu'aucune clé ne la porte.
        |
        | Brancher un produit externe = une ligne ici, une clé scopée, un
        | endpoint déclaré par `payments:endpoint`.
        */
        'learn.enrollment' => ExternalPayable::class,
    ],

    'notchpay' => [
        'base_url' => env('NOTCHPAY_BASE_URL', 'https://api.notchpay.co'),

        // `Authorization` porte la clé **publique**, sans préfixe `Bearer`.
        // Une clé de test est préfixée `test_`.
        'public_key' => env('NOTCHPAY_PUBLIC_KEY'),

        // `X-Grant`. Exigé sur les soldes et les transferts uniquement — les
        // paiements n'en ont pas besoin. Renseigné pour le jour où le module
        // décaissera.
        'private_key' => env('NOTCHPAY_PRIVATE_KEY'),

        // Signature des callbacks : HMAC-SHA256 sur le corps brut, en-tête
        // `x-notch-signature`. Une vraie signature, contrairement au secret
        // partagé de Tranzak.
        'webhook_hash' => env('NOTCHPAY_WEBHOOK_HASH'),

        // URL de rappel par paiement. Vide = celle du tableau de bord. Utile
        // quand un seul compte marchand sert plusieurs environnements.
        'callback_url' => env('NOTCHPAY_CALLBACK_URL'),

        'timeout' => 20,
    ],

    'tranzak' => [
        'base_url' => env('TRANZAK_BASE_URL', 'https://sandbox.dsapi.tranzak.me'),
        'app_id' => env('TRANZAK_APP_ID'),
        'app_key' => env('TRANZAK_APP_KEY'),

        // Le `authKey` des callbacks est un secret partagé transporté dans le
        // corps, pas une signature : il prouve que l'émetteur connaît le
        // secret, jamais que le corps est intact.
        'auth_key' => env('TRANZAK_AUTH_KEY'),

        'timeout' => 20,
    ],

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
    ],

    /*
    |--------------------------------------------------------------------------
    | Produits externes
    |--------------------------------------------------------------------------
    |
    | Le webhook sortant n'est **jamais** la garantie : c'est l'accélérateur. Un
    | produit qui ne met en place que lui aura tôt ou tard un client payé sans
    | service. Le sondage et la réconciliation restent obligatoires par contrat.
    |
    | Court, le délai : la livraison se fait depuis une file, et un produit
    | injoignable ne doit pas immobiliser un worker. Les réessais suivent la
    | cadence de Notify — 1 min, 5 min, 30 min, 2 h, 6 h.
    |
    */

    'external' => [
        'delivery_timeout' => 10,
    ],

    /*
    |--------------------------------------------------------------------------
    | Réseaux mobiles
    |--------------------------------------------------------------------------
    |
    | Le réseau du payeur est un **fait** déduit du numéro, pas un choix. Il
    | détermine quels agrégateurs peuvent servir, jamais lequel est essayé en
    | premier — cet ordre-là est celui de `providers`.
    |
    | Distinct du pays de **facturation**, qui détermine la TVA : une plateforme
    | camerounaise peut encaisser un numéro d'un autre réseau.
    |
    */

    'default_country' => env('PAYMENTS_COUNTRY', 'CM'),

    'operators' => [
        'CM' => [
            'country_code' => '237',
            'prefixes' => [
                'mtn' => ['67', '68', '650', '651', '652', '653', '654'],
                'orange' => ['69', '655', '656', '657', '658', '659'],
            ],
        ],
    ],

];
