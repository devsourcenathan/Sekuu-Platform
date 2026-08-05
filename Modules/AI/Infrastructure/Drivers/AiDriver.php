<?php

declare(strict_types=1);

namespace Modules\AI\Infrastructure\Drivers;

use Modules\AI\Domain\Models\AiAccount;

/**
 * Un protocole de fournisseur.
 *
 * ## Ce que ce contrat ne porte pas : le prix
 *
 * Quatre méthodes, et **aucune ne parle d'argent**. Le tarif appartient au
 * registre des modèles : le même `llama-3.3-70b` coûte trois prix chez trois
 * hébergeurs, et le protocole n'y est pour rien.
 *
 * `generate()` rend des **jetons**. La conversion est faite au-dessus, par ce
 * qui sait aussi à qui appartient le compte — donc si le chiffre est exact ou
 * estimé.
 *
 * ## Ajouter une famille demande une classe
 *
 * C'est irréductible : un pilote doit savoir **authentifier** chez son
 * fournisseur, ce qui est un protocole et non un paramètre. Ajouter un
 * *service* qui parle un protocole déjà connu est, lui, une ligne de
 * configuration.
 *
 * @see docs/03-services/ai/05-providers.md
 * @see docs/04-decisions/adr-0017-ai-accounts.md
 */
interface AiDriver
{
    public function capabilities(): DriverCapabilities;

    /**
     * Ce compte sert-il ce modèle ?
     *
     * Un compte peut restreindre explicitement sa liste ; sinon le pilote
     * répond selon ce qu'il sait servir.
     */
    public function serves(AiAccount $account, string $model): bool;

    /**
     * Exécute, ou lève.
     *
     * Les exceptions sont classées au-dessus : ce qui compte ici est de laisser
     * remonter assez d'information pour distinguer un refus d'identifiants d'un
     * réseau injoignable.
     */
    public function generate(AiAccount $account, GenerationRequest $request): GenerationResult;

    /**
     * La plus petite génération possible, pour l'épreuve.
     *
     * **Elle consomme réellement des jetons.** Une épreuve qui ne
     * consommerait rien — lister les modèles — ne prouverait pas ce qui compte :
     * un compte peut lister sans avoir de crédit.
     */
    public function probe(AiAccount $account): void;
}
