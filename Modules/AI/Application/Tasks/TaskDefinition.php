<?php

declare(strict_types=1);

namespace Modules\AI\Application\Tasks;

/**
 * Ce qu'une tâche déclare.
 *
 * Une tâche est **du code**, pas une donnée : elle porte un modèle, une
 * température et un format de sortie — c'est-à-dire un comportement facturé. La
 * rendre modifiable par API permettrait de changer un modèle sans revue ni
 * test, en silence.
 *
 * C'est la différence avec un compte, qui est une clé qu'on remplace.
 *
 * @see docs/04-decisions/adr-0015-ai-task-not-model.md
 */
final readonly class TaskDefinition
{
    /**
     * @param  string  $model  Nommé par la plateforme, jamais par l'appelant
     * @param  string|null  $fallback  `null` = échouer franchement plutôt que changer de forme
     * @param  list<string>  $requires  Capacités exigées — vérifiées contre le registre au démarrage
     * @param  array<string, string>  $inputs  Règles de validation des entrées
     */
    public function __construct(
        public string $name,
        public string $model,
        public ?string $fallback,
        public int $maxInputTokens,
        public int $maxOutputTokens,
        public float $temperature,
        public string $output,
        public array $requires,
        public array $inputs,
        public bool $synchronous,
        public bool $acceptsHistory,
        public ?int $retainDays,
        public ?string $instructions,
    ) {}

    /**
     * @param  array<string, mixed>  $config
     */
    public static function fromConfig(string $name, array $config): self
    {
        return new self(
            name: $name,
            model: (string) $config['model'],
            fallback: $config['fallback'] ?? null,
            maxInputTokens: (int) ($config['max_input_tokens'] ?? 32_000),
            maxOutputTokens: (int) ($config['max_output_tokens'] ?? 2_000),
            temperature: (float) ($config['temperature'] ?? 0.2),
            output: (string) ($config['output'] ?? 'text'),
            requires: array_values((array) ($config['requires'] ?? [])),
            inputs: (array) ($config['inputs'] ?? []),
            synchronous: (bool) ($config['synchronous'] ?? false),
            acceptsHistory: (bool) ($config['accepts_history'] ?? false),

            // `null` = rien n'est conservé. C'est le défaut, et il n'est jamais
            // implicite dans l'autre sens : conserver se déclare.
            retainDays: $config['retain_days'] ?? null,

            instructions: $config['instructions'] ?? null,
        );
    }

    /**
     * La chaîne de modèles, dans l'ordre d'essai.
     *
     * @return list<string>
     */
    public function chain(): array
    {
        return $this->fallback === null ? [$this->model] : [$this->model, $this->fallback];
    }

    public function producesJson(): bool
    {
        return $this->output === 'json';
    }

    /**
     * Une tâche libre n'impose aucune forme de sortie.
     *
     * Ce que l'ADR-0015 refuse est que l'appelant nomme le **modèle**, pas
     * qu'il écrive librement. Ce qu'une tâche libre perd est réel — aucune
     * validation — et ce sont les bornes de jetons qui tiennent le coût à sa
     * place.
     */
    public function isFreeForm(): bool
    {
        return $this->instructions === null && $this->output === 'text';
    }
}
