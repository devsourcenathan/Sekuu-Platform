<?php

declare(strict_types=1);

namespace Modules\Storage\Tests\Support;

use App\Platform\Contracts\AttachedFile;
use App\Platform\Contracts\FileActor;
use App\Platform\Contracts\FileOwner;
use App\Platform\Contracts\FilePolicy;
use App\Platform\Contracts\FileRef;

/**
 * Propriétaire factice, imitant un module qui n'est ni Billing ni Identity.
 *
 * Existe pour éprouver Storage **sans facture**, ce qui est la démonstration
 * littérale de son indépendance : aucun de ses fichiers n'importe un autre
 * module, et le registre suffit à le brancher.
 */
final class FakeFileOwner implements FileOwner
{
    public const TYPE = 'learn.lesson';

    /** @var list<string> */
    public static array $mimeTypes = [];

    public static ?int $maxBytes = null;

    public static ?string $destination = null;

    public static ?string $fallback = null;

    public static ?int $retainDays = null;

    public static bool $allowsAttach = true;

    public static bool $allowsRead = true;

    /** @var list<string> */
    public static array $attached = [];

    /** @var list<string> */
    public static array $detached = [];

    public static function reset(): void
    {
        self::$mimeTypes = [];
        self::$maxBytes = null;
        self::$destination = null;
        self::$fallback = null;
        self::$retainDays = null;
        self::$allowsAttach = true;
        self::$allowsRead = true;
        self::$attached = [];
        self::$detached = [];
    }

    public function policy(FileRef $ref, FileActor $actor): FilePolicy
    {
        if (! self::$allowsAttach) {
            return FilePolicy::refuse();
        }

        return FilePolicy::allow(
            mimeTypes: self::$mimeTypes,
            maxBytes: self::$maxBytes,
            destination: self::$destination,
            fallback: self::$fallback,
            retainDays: self::$retainDays,
        );
    }

    public function mayRead(FileRef $ref, FileActor $actor): bool
    {
        return self::$allowsRead;
    }

    public function attached(AttachedFile $file): void
    {
        self::$attached[] = $file->fileId;
    }

    public function detached(AttachedFile $file): void
    {
        self::$detached[] = $file->fileId;
    }
}
