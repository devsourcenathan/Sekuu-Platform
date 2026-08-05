<?php

declare(strict_types=1);

namespace Modules\Storage\Infrastructure\Drivers;

/**
 * Ce que le client annonce vouloir écrire.
 *
 * Le mot « annonce » est le sujet du type : rien ici ne fait foi. Ces valeurs
 * servent à signer une autorisation étroite et à refuser tôt ce qui sera de
 * toute façon refusé — jamais à décider de l'issue, qui est constatée à la
 * confirmation.
 */
final readonly class UploadIntent
{
    public function __construct(
        public string $mimeType,
        public int $size,
        public int $ttlSeconds,
    ) {}
}
