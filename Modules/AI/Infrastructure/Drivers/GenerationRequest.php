<?php

declare(strict_types=1);

namespace Modules\AI\Infrastructure\Drivers;

/**
 * Ce qu'un pilote reçoit.
 *
 * Le **modèle y figure**, et c'est normal : à ce niveau, il a déjà été choisi
 * par la plateforme. L'invariant de l'ADR-0015 porte sur l'API publique, pas
 * sur la couche qui exécute.
 */
final readonly class GenerationRequest
{
    /**
     * @param  list<array{role: string, content: string}>  $history  Fourni par l'appelant ; le module ne garde aucun fil
     */
    public function __construct(
        public string $model,
        public string $prompt,
        public ?string $instructions,
        public int $maxOutputTokens,
        public float $temperature,
        public bool $json,
        public array $history = [],
    ) {}
}
