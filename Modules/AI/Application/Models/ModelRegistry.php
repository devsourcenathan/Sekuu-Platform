<?php

declare(strict_types=1);

namespace Modules\AI\Application\Models;

use App\Platform\Exceptions\DomainException;

/**
 * Le catalogue des modèles.
 *
 * Deux pilotes et treize services donnent accès à des dizaines de modèles. Sans
 * registre, personne ne sait lequel coûte quoi, lequel sait produire du JSON,
 * ni lequel a été retiré la semaine dernière.
 *
 * @see docs/03-services/ai/05-providers.md
 */
final class ModelRegistry
{
    /** @var array<string, ModelDefinition> */
    private array $models = [];

    public function register(ModelDefinition $model): void
    {
        $this->models[$model->id] = $model;
    }

    /**
     * Un modèle absent du registre est refusé.
     *
     * Une tâche ne peut pas nommer un modèle que personne n'a tarifé : ce
     * serait une facture qu'on ne saurait pas imputer.
     */
    public function get(string $id): ModelDefinition
    {
        return $this->models[$id] ?? throw DomainException::unprocessable(
            'AI_MODEL_UNKNOWN',
            __('ai::messages.model_unknown', ['model' => $id]),
        );
    }

    public function knows(string $id): bool
    {
        return isset($this->models[$id]);
    }

    /**
     * @return array<string, ModelDefinition>
     */
    public function all(): array
    {
        return $this->models;
    }

    /**
     * @return list<ModelDefinition>
     */
    public function deprecated(): array
    {
        return array_values(array_filter($this->models, fn (ModelDefinition $m): bool => $m->isDeprecated()));
    }
}
