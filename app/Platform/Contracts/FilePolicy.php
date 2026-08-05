<?php

declare(strict_types=1);

namespace App\Platform\Contracts;

/**
 * Ce que le propriétaire d'un objet répond quand on lui demande si un fichier
 * peut lui être rattaché, et sous quelles bornes.
 *
 * ## Pourquoi une politique plutôt qu'une configuration
 *
 * On aurait pu lister les types autorisés dans `config/storage.php`, par
 * `owner_type`. Mais les bornes dépendent souvent de l'objet et non de son
 * type : une leçon d'un plan gratuit n'accepte pas la même taille qu'une leçon
 * d'un plan payant, et un brouillon accepte ce qu'un cours publié refuse.
 *
 * Une configuration statique obligerait le propriétaire à revérifier après
 * coup — c'est-à-dire une fois les octets écrits.
 *
 * C'est l'exact pendant de {@see PayableQuote} : une seule question, posée
 * avant tout effet de bord, qui porte à la fois l'autorisation et les bornes.
 *
 * @see docs/03-services/storage/05-integration.md
 */
final readonly class FilePolicy
{
    /**
     * @param  list<string>  $mimeTypes  Vide = aucun type imposé par le propriétaire
     */
    private function __construct(
        public bool $allowed,
        public array $mimeTypes,
        public ?int $maxBytes,
        public ?string $destination,
        public ?string $fallback,
        public ?int $retainDays,
        public ?string $refusalCode,
        public ?string $refusalMessage,
    ) {}

    /**
     * @param  list<string>  $mimeTypes
     * @param  string|null  $destination  Nom d'une destination — voir docs/03-services/storage/06-destinations.md §4
     * @param  string|null  $fallback  Second choix, essayé **uniquement** si le premier est indisponible
     */
    public static function allow(
        array $mimeTypes = [],
        ?int $maxBytes = null,
        ?string $destination = null,
        ?string $fallback = null,
        ?int $retainDays = null,
    ): self {
        return new self(true, $mimeTypes, $maxBytes, $destination, $fallback, $retainDays, null, null);
    }

    /**
     * Le propriétaire refuse — objet inexistant, acteur non habilité, état
     * incompatible.
     *
     * Il n'a pas à choisir le code HTTP : il répond à une question métier, la
     * couche de stockage traduit. C'est ce qui permet à la règle « ne jamais
     * distinguer inexistant de pas-à-vous » de tenir en un seul endroit plutôt
     * que dans chaque module.
     */
    public static function refuse(?string $code = null, ?string $message = null): self
    {
        return new self(false, [], null, null, null, null, $code, $message);
    }

    /**
     * Un `fallback` sans `destination` n'a aucun sens : il n'y aurait rien à
     * quoi se substituer. Le signaler tôt évite une politique qui paraît poser
     * un repli et n'en pose aucun.
     */
    public function isCoherent(): bool
    {
        return $this->fallback === null || $this->destination !== null;
    }

    public function acceptsMimeType(string $mimeType): bool
    {
        return $this->mimeTypes === [] || in_array($mimeType, $this->mimeTypes, true);
    }

    public function acceptsSize(int $bytes): bool
    {
        return $this->maxBytes === null || $bytes <= $this->maxBytes;
    }
}
