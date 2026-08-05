<?php

declare(strict_types=1);

namespace App\Platform\Contracts;

/**
 * Qui demande une génération.
 *
 * Trois formes, comme pour {@see FileActor}. Un utilisateur, une clé d'API d'un
 * produit externe, et la plateforme elle-même quand un module appelle pour son
 * propre compte.
 *
 * L'`organizationId` n'est **jamais optionnel** en pratique : sans lui, une
 * génération ne serait imputée à personne — ni pour le quota, ni pour la
 * facture. Une plateforme qui ne sait pas qui a dépensé ne sait pas non plus
 * qui facturer.
 *
 * @see docs/03-services/ai/06-integration.md
 */
final readonly class AiActor
{
    public const USER = 'user';

    public const API_KEY = 'api_key';

    public const SYSTEM = 'system';

    /**
     * @param  list<string>  $tasks  Tâches que cet acteur peut demander ; vide = aucune borne propre
     */
    private function __construct(
        public string $type,
        public ?string $id,
        public ?string $organizationId,
        public array $tasks,
    ) {}

    public static function user(string $userId, ?string $organizationId = null): self
    {
        return new self(self::USER, $userId, $organizationId, []);
    }

    /**
     * Une clé porte sa liste blanche de tâches.
     *
     * Le catalogue dit quelles tâches **existent**, la clé dit lesquelles
     * **ce produit-là** peut demander. Une tâche ajoutée n'habilite personne
     * tant qu'aucune clé ne la porte — la double borne déjà posée côté
     * paiement.
     *
     * @param  list<string>  $tasks
     */
    public static function apiKey(string $keyId, array $tasks, ?string $organizationId = null): self
    {
        return new self(self::API_KEY, $keyId, $organizationId, $tasks);
    }

    public static function system(?string $organizationId = null): self
    {
        return new self(self::SYSTEM, null, $organizationId, []);
    }

    public function isSystem(): bool
    {
        return $this->type === self::SYSTEM;
    }

    public function isExternal(): bool
    {
        return $this->type === self::API_KEY;
    }

    /**
     * Une liste vide n'est pas un acteur tout-puissant : c'est un acteur dont
     * les bornes sont ailleurs — le quota, le plafond, et le catalogue.
     */
    public function mayRun(string $task): bool
    {
        return $this->tasks === [] || in_array($task, $this->tasks, true);
    }
}
