<?php

declare(strict_types=1);

namespace Modules\AI\Infrastructure\Drivers;

/**
 * Ce qu'un pilote rend : une sortie, et **des jetons — jamais un montant**.
 *
 * La conversion en coût est faite au-dessus, par ce qui connaît le registre des
 * modèles — et sait aussi si le compte appartient à la plateforme ou à un
 * client, donc si le chiffre est exact ou estimé.
 */
final readonly class GenerationResult
{
    public function __construct(
        public string $output,
        public int $inputTokens,
        public int $outputTokens,
        public int $latencyMs,
    ) {}
}
