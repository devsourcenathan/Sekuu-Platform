<?php

declare(strict_types=1);

namespace Modules\AI\Application\Models;

/**
 * Un modèle, ce qu'il sait faire et ce qu'il coûte.
 *
 * Le prix vit ici plutôt que dans le pilote : le même `llama-3.3-70b` coûte
 * trois prix chez trois hébergeurs, et le protocole n'y est pour rien.
 *
 * @see docs/03-services/ai/05-providers.md
 */
final readonly class ModelDefinition
{
    /** Choisi sans réserve. */
    public const PREFERRED = 'preferred';

    /** Fonctionne encore, mais son retrait est annoncé. Journalisé. */
    public const DEPRECATED = 'deprecated';

    /** Refusé par la résolution : la tâche passe à son repli, ou échoue. */
    public const RETIRED = 'retired';

    /**
     * @param  string  $family  Le pilote qui sait lui parler
     * @param  list<string>  $capabilities  `json`, `tools`, `vision`
     * @param  float|null  $priceIn  Par million de jetons. `null` = pas de prix public
     */
    public function __construct(
        public string $id,
        public string $family,
        public int $context,
        public array $capabilities,
        public ?float $priceIn,
        public ?float $priceOut,
        public string $status,
    ) {}

    /**
     * @param  array<string, mixed>  $config
     */
    public static function fromConfig(string $id, array $config): self
    {
        return new self(
            id: $id,
            family: (string) $config['family'],
            context: (int) ($config['context'] ?? 128_000),
            capabilities: array_values((array) ($config['capabilities'] ?? [])),
            priceIn: $config['price_in'] ?? null,
            priceOut: $config['price_out'] ?? null,
            status: (string) ($config['status'] ?? self::PREFERRED),
        );
    }

    public function isRetired(): bool
    {
        return $this->status === self::RETIRED;
    }

    public function isDeprecated(): bool
    {
        return $this->status === self::DEPRECATED;
    }

    /**
     * @param  list<string>  $capabilities
     */
    public function satisfies(array $capabilities): bool
    {
        return array_diff($capabilities, $this->capabilities) === [];
    }

    public function hasPublicPrice(): bool
    {
        return $this->priceIn !== null && $this->priceOut !== null;
    }

    /**
     * Le coût, en **millionièmes d'unité**.
     *
     * `null` quand le modèle n'a pas de prix public — un modèle auto-hébergé ne
     * coûte pas par jeton mais par heure de machine. `null` dit « on ne sait
     * pas » ; zéro dirait « gratuit », et un tableau de coûts qui affiche zéro
     * pour une machine qu'on loue à l'heure est un tableau qui ment.
     */
    public function costMicros(int $inputTokens, int $outputTokens): ?int
    {
        if (! $this->hasPublicPrice()) {
            return null;
        }

        $dollars = ($inputTokens * $this->priceIn + $outputTokens * $this->priceOut) / 1_000_000;

        return (int) round($dollars * 1_000_000);
    }
}
