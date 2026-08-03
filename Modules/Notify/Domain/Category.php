<?php

declare(strict_types=1);

namespace Modules\Notify\Domain;

/**
 * @see docs/04-decisions/adr-0006-transactional-vs-marketing.md
 */
final class Category
{
    /** Ne peut jamais être désactivée : couper ces messages enferme l'utilisateur dehors. */
    public const TRANSACTIONAL = 'transactional';

    public const OPERATIONAL = 'operational';

    public const MARKETING = 'marketing';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [self::TRANSACTIONAL, self::OPERATIONAL, self::MARKETING];
    }

    public static function isOptional(string $category): bool
    {
        return $category !== self::TRANSACTIONAL;
    }

    /**
     * Valeur par défaut lorsqu'aucune préférence n'est enregistrée.
     */
    public static function enabledByDefault(string $category): bool
    {
        return $category !== self::MARKETING;
    }
}
