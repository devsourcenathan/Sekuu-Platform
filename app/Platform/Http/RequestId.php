<?php

declare(strict_types=1);

namespace App\Platform\Http;

use Illuminate\Support\Str;

/**
 * Identifiant unique de requête, partagé entre les logs, les réponses
 * et les traces distribuées.
 *
 * @see docs/02-standards/api-guidelines.md
 */
final class RequestId
{
    public const HEADER = 'X-Request-Id';

    private static ?string $current = null;

    public static function current(): string
    {
        return self::$current ??= self::generate();
    }

    public static function set(string $id): void
    {
        self::$current = $id;
    }

    public static function reset(): void
    {
        self::$current = null;
    }

    public static function generate(): string
    {
        return 'req_'.Str::lower(Str::random(12));
    }

    /**
     * Un identifiant fourni par un client n'est accepté que s'il est court
     * et alphanumérique : il finit dans les logs, on ne lui fait pas confiance.
     */
    public static function isAcceptable(string $id): bool
    {
        return $id !== '' && preg_match('/^[A-Za-z0-9_\-]{1,64}$/', $id) === 1;
    }
}
