<?php

declare(strict_types=1);

namespace Modules\Storage\Infrastructure\Drivers;

use Modules\Storage\Domain\Models\Destination;

/**
 * Un protocole de magasin.
 *
 * ## Pourquoi ceci est du code, et pas de la configuration
 *
 * Ajouter un **compte**, ou un service compatible S3, ne demande qu'une ligne
 * en base. Ajouter une **famille** — Google Drive, Azure Blob — demande cette
 * classe, et c'est irréductible : un pilote doit savoir fabriquer une
 * autorisation d'écriture chez son fournisseur, ce qui est un protocole
 * d'authentification et non un paramètre.
 *
 * Google Drive ouvre une session reprenable après un `POST` authentifié en
 * OAuth ; S3 signe une URL par dérivation HMAC. Rendre cela configurable
 * reviendrait à écrire un langage de description de requêtes HTTP dans du
 * YAML — c'est-à-dire du code, dans un langage plus pauvre, sans types et sans
 * tests.
 *
 * Ce que le contrat garantit à la place : cinq méthodes, et rien d'autre dans
 * la plateforme ne change.
 *
 * @see docs/04-decisions/adr-0014-storage-destinations.md
 */
interface StorageDriver
{
    public function capabilities(): DriverCapabilities;

    public function uploadTicket(Destination $to, string $path, UploadIntent $intent): UploadTicket;

    /**
     * Ce que le magasin dit de l'objet, ou `null` s'il n'y est pas.
     *
     * `null` n'est pas une erreur : c'est la réponse attendue quand un client a
     * demandé une URL et n'a jamais écrit.
     */
    public function inspect(Destination $at, string $path): ?ObjectFacts;

    public function readUrl(Destination $at, string $path, int $seconds): string;

    public function delete(Destination $at, string $path): void;

    /**
     * Écrit des octets directement.
     *
     * Pour le cas où c'est la plateforme qui les produit — Billing mettant en
     * page une facture. L'URL signée n'a alors pas d'objet : le chemin en deux
     * temps existe pour les octets qui viennent d'ailleurs.
     */
    public function put(Destination $at, string $path, string $contents, string $mimeType): void;
}
