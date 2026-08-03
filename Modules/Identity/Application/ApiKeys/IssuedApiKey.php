<?php

declare(strict_types=1);

namespace Modules\Identity\Application\ApiKeys;

use Modules\Identity\Domain\Models\ApiKey;

/**
 * La valeur en clair n'existe qu'ici, le temps de l'afficher une fois. Elle
 * n'est jamais relisible : seul son hachage est conservé.
 */
final readonly class IssuedApiKey
{
    public function __construct(
        public ApiKey $key,
        public string $plainKey,
    ) {}
}
