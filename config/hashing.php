<?php

declare(strict_types=1);

/*
| Argon2id est l'algorithme retenu pour les mots de passe.
|
| La suite de tests bascule sur bcrypt à faible coût (phpunit.xml) : hacher en
| Argon2id à chaque test rendrait la suite inutilisablement lente.
|
| @see docs/02-standards/security.md
*/

return [

    'driver' => env('HASH_DRIVER', 'argon2id'),

    'bcrypt' => [
        'rounds' => env('BCRYPT_ROUNDS', 12),
        'verify' => true,
    ],

    'argon' => [
        'memory' => env('ARGON_MEMORY', 65536),
        'threads' => env('ARGON_THREADS', 1),
        'time' => env('ARGON_TIME', 4),
        'verify' => true,
    ],

];
