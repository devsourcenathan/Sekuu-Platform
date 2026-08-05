<?php

declare(strict_types=1);

namespace Modules\Storage\Infrastructure\Drivers;

/**
 * Ce qu'un pilote sait faire.
 *
 * Les pilotes ne savent pas tous la même chose, et l'API doit répondre en
 * conséquence plutôt que supposer. Un client qui suit ce que le pilote annonce
 * fonctionne partout ; un client qui suppose `PUT` casse le jour où le magasin
 * change, sans que rien ne l'ait prévenu.
 *
 * @see docs/03-services/storage/06-destinations.md
 */
final readonly class DriverCapabilities
{
    /**
     * @param  bool  $directUpload  Le client écrit sans passer par la plateforme
     * @param  bool  $checksums  Le magasin rend une empreinte exploitable
     * @param  int  $maxObjectBytes  Borne du fournisseur, ou du mode mandataire
     */
    public function __construct(
        public bool $directUpload,
        public bool $temporaryUrls,
        public bool $checksums,
        public int $maxObjectBytes,
    ) {}

    /**
     * Sans téléversement direct, les octets transitent — et la borne devient
     * étroite.
     *
     * 25 Mo tient dans un processus PHP et dans les délais d'un proxy. Une
     * facture ou une pièce jointe passent ; une vidéo est refusée, et le
     * message dit que la destination en est la cause.
     *
     * L'exception à l'ADR-0012 est ainsi déclarée par le pilote et bornée là où
     * elle ne dépend plus de la bonne volonté de l'appelant.
     */
    public const PROXY_MAX_BYTES = 25 * 1024 * 1024;
}
