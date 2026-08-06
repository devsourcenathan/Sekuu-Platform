<?php

declare(strict_types=1);

namespace Modules\AI\Infrastructure\Console;

use Illuminate\Console\Command;
use Modules\AI\Application\Accounts\VerifyAccount;
use Modules\AI\Domain\Models\AiAccount;

/**
 * Rejouer l'épreuve.
 *
 * ## Quotidienne, et pas plus
 *
 * Ce qui casse un compte arrive **après** l'enregistrement : une clé révoquée,
 * un crédit épuisé, un modèle retiré par le fournisseur. Sans cette reprise, un
 * compte cassé se découvre à la génération suivante — c'est-à-dire par un
 * client, et pour un produit externe, par *son* client.
 *
 * Mais l'épreuve consomme de vrais jetons, contrairement à celle de Storage qui
 * écrit trente octets gratuits. La cadence horaire multiplierait un petit
 * montant par le nombre de comptes et le nombre d'heures, pour surveiller une
 * chose qui change rarement.
 *
 * ## Elle est aussi la reprise
 *
 * Un compte `unverified` repasse `active` de lui-même dès que sa clé est
 * corrigée, sans déploiement. C'est ce qui rend supportable qu'un amorçage par
 * l'environnement échoue en silence.
 *
 * Un compte `disabled` n'est jamais rallumé : il dit que la clé n'est plus la
 * nôtre, et l'épreuve n'a pas à défaire une décision humaine.
 */
final class VerifyAccountsCommand extends Command
{
    protected $signature = 'ai:verify {slug? : Un compte en particulier}';

    protected $description = 'Éprouve les comptes : une génération réelle d\'un jeton.';

    public function handle(VerifyAccount $verifier): int
    {
        $query = AiAccount::query()
            ->where('environment', app()->environment())
            ->where('status', '<>', AiAccount::DISABLED);

        if ($slug = $this->argument('slug')) {
            // Nommé explicitement, on l'éprouve même désactivé : c'est un geste
            // humain, et il peut vouloir savoir avant de rallumer.
            $query = AiAccount::query()->where('slug', $slug);
        }

        $comptes = $query->orderBy('priority')->orderBy('slug')->get();

        if ($comptes->isEmpty()) {
            $this->info('Aucun compte à éprouver.');

            return self::SUCCESS;
        }

        $echecs = 0;

        foreach ($comptes as $compte) {
            $ok = $verifier->handle($compte);
            $compte->refresh();

            if ($ok) {
                $this->line("  <fg=green>✓</> {$compte->slug}");

                continue;
            }

            $echecs++;
            $this->line("  <fg=red>✗</> {$compte->slug} — {$compte->verification_reason}");
        }

        $this->newLine();

        if ($echecs > 0) {
            $this->warn("{$echecs} compte(s) hors service. Les tâches qui en dépendaient basculeront ou échoueront.");
        }

        // Sortie non nulle : un ordonnanceur doit pouvoir s'en apercevoir sans
        // lire la sortie.
        return $echecs > 0 ? self::FAILURE : self::SUCCESS;
    }
}
