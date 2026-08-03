<?php

declare(strict_types=1);

/*
| Configuration du module Identity.
|
| @see docs/02-standards/security.md
*/

return [

    'jwt' => [
        // Émetteur : doit correspondre au claim `iss` vérifié par les consommateurs.
        'issuer' => env('IDENTITY_JWT_ISSUER', 'https://identity.sekuu.com'),

        // Audiences par défaut d'un token. Un consommateur rejette tout token
        // dont il n'est pas destinataire.
        'audience' => array_values(array_filter(
            explode(',', (string) env('IDENTITY_JWT_AUDIENCE', 'sekuu-platform'))
        )),

        'algorithm' => 'RS256',

        // 15 minutes : plafonne la fenêtre pendant laquelle un token révoqué
        // reste techniquement valide.
        'access_ttl' => (int) env('IDENTITY_ACCESS_TTL', 900),

        // Clés PEM. Vides en développement : une paire est alors générée et
        // conservée dans storage/. En production, elles proviennent du
        // gestionnaire de secrets et ne sont jamais versionnées.
        'private_key' => env('IDENTITY_JWT_PRIVATE_KEY'),
        'public_key' => env('IDENTITY_JWT_PUBLIC_KEY'),

        // Tolérance d'horloge, en secondes.
        'leeway' => 60,
    ],

    'refresh_token' => [
        // 30 jours.
        'ttl' => (int) env('IDENTITY_REFRESH_TTL', 2592000),
        'cookie' => 'sekuu_refresh_token',
    ],

    'session' => [
        // Durée de vie d'un appareil connecté, alignée sur le refresh token.
        'ttl' => (int) env('IDENTITY_SESSION_TTL', 2592000),
    ],

    'tokens' => [
        // Jetons d'action à usage unique.
        'password_reset_ttl' => (int) env('IDENTITY_PASSWORD_RESET_TTL', 3600),
        'email_verification_ttl' => (int) env('IDENTITY_EMAIL_VERIFICATION_TTL', 86400),
    ],

    'password' => [
        'min_length' => 12,

        // Nombre de mots de passe précédents conservés (hachés) pour empêcher
        // une réutilisation immédiate.
        'history' => 5,

        // Vérification contre les fuites connues (appel réseau) : activée
        // hors développement et hors tests.
        'check_compromised' => env('IDENTITY_CHECK_COMPROMISED_PASSWORDS', false),
    ],

];
