<?php

declare(strict_types=1);

use Modules\Notify\Domain\Channel;
use Modules\Notify\Infrastructure\Providers\LaravelMailProvider;
use Modules\Notify\Infrastructure\Providers\LocalGatewaySmsProvider;
use Modules\Notify\Infrastructure\Providers\PostmarkMailProvider;
use Modules\Notify\Infrastructure\Providers\ResendMailProvider;
use Modules\Notify\Infrastructure\Providers\TwilioSmsProvider;
use Modules\Notify\Infrastructure\Webhooks\LocalGatewayWebhookHandler;
use Modules\Notify\Infrastructure\Webhooks\PostmarkWebhookHandler;
use Modules\Notify\Infrastructure\Webhooks\ResendWebhookHandler;

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
    | bascule en cas d'échec **infrastructurel**. Un rejet métier — numéro hors
    | réseau, contenu refusé — n'entraîne aucune bascule : il ne réussira pas
    | davantage ailleurs, et chaque tentative coûte.
    |
    */

    'providers' => [
        // Un fournisseur non configuré n'est pas essayé : en développement,
        // Postmark est ignoré et le mailer Laravel prend la main.
        Channel::EMAIL => [
            ResendMailProvider::class,
            PostmarkMailProvider::class,
            LaravelMailProvider::class,
        ],

        // Sur les marchés visés, un acheminement local est moins cher et mieux
        // délivré qu'un envoi international : c'est le premier rang, pas un
        // repli.
        Channel::SMS => [
            LocalGatewaySmsProvider::class,
            TwilioSmsProvider::class,
        ],

        Channel::WHATSAPP => [],
        Channel::PUSH => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Retours de livraison
    |--------------------------------------------------------------------------
    |
    | Sans ces webhooks, la liste de suppression ne s'alimente jamais et le
    | statut `sent` ne devient jamais `delivered`.
    |
    */

    'webhooks' => [
        'resend' => ResendWebhookHandler::class,
        'postmark' => PostmarkWebhookHandler::class,
        'local-gateway' => LocalGatewayWebhookHandler::class,
    ],

    'sms' => [
        'local_gateway' => [
            'endpoint' => env('SMS_GATEWAY_ENDPOINT'),
            'token' => env('SMS_GATEWAY_TOKEN'),
            'sender_id' => env('SMS_GATEWAY_SENDER_ID', 'SEKUU'),
            'timeout' => 10,
            'webhook_secret' => env('SMS_GATEWAY_WEBHOOK_SECRET'),
        ],

        'twilio' => [
            'account_sid' => env('TWILIO_ACCOUNT_SID'),
            'token' => env('TWILIO_TOKEN'),
            'from' => env('TWILIO_FROM'),
            'timeout' => 10,
        ],
    ],

    'email' => [
        'resend' => [
            'api_key' => env('RESEND_API_KEY'),

            // Le domaine doit être vérifié chez Resend (DKIM + Return-Path),
            // sinon les messages partent non signés et finissent en spam.
            'from' => env('RESEND_FROM', 'Sekuu <no-reply@sekuu.com>'),

            'webhook_secret' => env('RESEND_WEBHOOK_SECRET'),
            'timeout' => 10,
        ],

        'postmark' => [
            'server_token' => env('POSTMARK_SERVER_TOKEN'),
            'from' => env('POSTMARK_FROM'),
            'timeout' => 10,

            // Postmark exige que les envois de masse passent par un flux
            // dédié : les mélanger dégraderait la réputation du flux
            // transactionnel, celui dont dépendent les liens de
            // réinitialisation.
            'transactional_stream' => env('POSTMARK_TRANSACTIONAL_STREAM', 'outbound'),
            'broadcast_stream' => env('POSTMARK_BROADCAST_STREAM', 'broadcast'),

            'webhook_token' => env('POSTMARK_WEBHOOK_TOKEN'),
        ],
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
