<?php

declare(strict_types=1);

namespace Modules\Storage\Infrastructure\Console;

use Illuminate\Console\Command;
use Modules\Storage\Application\Destinations\VerifyDestination;
use Modules\Storage\Domain\Models\Destination;

/**
 * Rejouer l'épreuve.
 *
 * Quotidienne, parce que ce qui casse une destination arrive **après**
 * l'enregistrement : une clé révoquée chez le fournisseur, un compartiment
 * supprimé, un droit d'écriture retiré.
 *
 * Sans elle, une destination cassée se découvre au téléversement suivant —
 * c'est-à-dire par un client, et pour un produit externe, par *son* client.
 */
final class VerifyDestinationsCommand extends Command
{
    protected $signature = 'storage:verify {slug? : Un magasin en particulier}';

    protected $description = 'Éprouve les magasins : écrire un témoin, le relire, l\'effacer.';

    public function handle(VerifyDestination $verifier): int
    {
        $query = Destination::query()->where('environment', app()->environment());

        if ($slug = $this->argument('slug')) {
            $query->where('slug', $slug);
        }

        $destinations = $query->orderBy('slug')->get();

        if ($destinations->isEmpty()) {
            $this->info('Aucun magasin à éprouver.');

            return self::SUCCESS;
        }

        $echecs = 0;

        foreach ($destinations as $destination) {
            $ok = $verifier->handle($destination);
            $destination->refresh();

            if ($ok) {
                $this->line("  <fg=green>✓</> {$destination->slug}");

                continue;
            }

            $echecs++;
            $this->line("  <fg=red>✗</> {$destination->slug} — {$destination->verification_reason}");
        }

        $this->newLine();

        if ($echecs > 0) {
            $this->warn("{$echecs} magasin(s) hors service. Les fichiers qu'ils portent restent lisibles s'ils sont en lecture seule.");
        }

        // Sortie non nulle : un ordonnanceur ou une CI doivent pouvoir s'en
        // apercevoir sans lire la sortie.
        return $echecs > 0 ? self::FAILURE : self::SUCCESS;
    }
}
