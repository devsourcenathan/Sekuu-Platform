<?php

declare(strict_types=1);

namespace App\Platform\Contracts;

/**
 * Qui demande à déposer ou à lire un fichier.
 *
 * Trois formes, et il en faut trois. Un utilisateur connecté, une clé d'API
 * d'un produit externe, et la plateforme elle-même quand elle produit les
 * octets — Billing mettant en page une facture n'a pas d'utilisateur derrière
 * lui.
 *
 * Distinct de {@see RequestContext}, qui exige un `userId` : un produit externe
 * n'en a pas, et lui en inventer un ferait entrer un utilisateur fictif dans le
 * journal des accès. Ce journal doit dire la vérité, c'est sa seule raison
 * d'être.
 *
 * @see docs/03-services/storage/02-data-model.md
 */
final readonly class FileActor
{
    public const USER = 'user';

    public const API_KEY = 'api_key';

    public const SYSTEM = 'system';

    /**
     * @param  string|null  $organizationId  Le porteur du quota, quand il y en a un
     * @param  list<string>  $ownerTypes  Types que cet acteur peut manipuler ; vide = aucune borne propre
     */
    private function __construct(
        public string $type,
        public ?string $id,
        public ?string $organizationId,
        public array $ownerTypes,
        public ?int $maxRetentionDays,
    ) {}

    public static function user(string $userId, ?string $organizationId = null): self
    {
        return new self(self::USER, $userId, $organizationId, [], null);
    }

    /**
     * Une clé d'API porte ses propres bornes, et elles n'ont pas de valeur par
     * défaut permissive.
     *
     * `maxRetentionDays` vaut zéro à l'émission : un produit externe ne peut
     * poser aucune rétention sur nos destinations tant qu'on ne la lui a pas
     * accordée. La clé **habilite**, elle n'hérite de rien — même mécanique que
     * la liste blanche de `subject_type` côté paiement.
     *
     * @param  list<string>  $ownerTypes
     */
    public static function apiKey(
        string $keyId,
        array $ownerTypes,
        ?string $organizationId = null,
        int $maxRetentionDays = 0,
    ): self {
        return new self(self::API_KEY, $keyId, $organizationId, $ownerTypes, $maxRetentionDays);
    }

    /**
     * La plateforme agissant pour elle-même : aucune borne, et aucun
     * utilisateur à inscrire au journal.
     */
    public static function system(?string $organizationId = null): self
    {
        return new self(self::SYSTEM, null, $organizationId, [], null);
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
     * Un acteur sans liste blanche n'est pas un acteur tout-puissant : c'est un
     * acteur dont les bornes sont ailleurs — chez le propriétaire de l'objet,
     * qui reste seul juge du droit.
     */
    public function mayTouch(string $ownerType): bool
    {
        return $this->ownerTypes === [] || in_array($ownerType, $this->ownerTypes, true);
    }
}
