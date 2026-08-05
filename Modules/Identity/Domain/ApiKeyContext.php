<?php

declare(strict_types=1);

namespace Modules\Identity\Domain;

use Modules\Identity\Domain\Models\ApiKey;

/**
 * Ce qui a été authentifié lorsqu'un service appelle avec une clé d'API.
 *
 * À la différence d'un access token, il n'y a **aucun utilisateur** : une clé
 * agit au nom d'une organisation, pas d'une personne.
 */
final readonly class ApiKeyContext
{
    public function __construct(public ApiKey $key) {}

    public function organizationId(): string
    {
        return $this->key->organization_id;
    }

    public function allowsSubjectType(string $subjectType): bool
    {
        return $this->key->allowsSubjectType($subjectType);
    }

    /**
     * @return list<string>
     */
    public function subjectTypes(): array
    {
        return array_values((array) ($this->key->subject_types ?? []));
    }

    /**
     * Combien de jours cette cle peut-elle rendre un fichier indestructible sur
     * nos destinations ? Zero a l'emission : la cle habilite, elle n'herite de
     * rien.
     */
    public function maxRetentionDays(): int
    {
        return (int) ($this->key->max_retention_days ?? 0);
    }
}
