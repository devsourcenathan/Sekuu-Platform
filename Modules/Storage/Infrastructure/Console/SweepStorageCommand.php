<?php

declare(strict_types=1);

namespace Modules\Storage\Infrastructure\Console;

use Illuminate\Console\Command;
use Modules\Storage\Domain\Models\Destination;
use Modules\Storage\Domain\Models\StoredFile;
use Modules\Storage\Infrastructure\Drivers\DriverRegistry;
use Throwable;

/**
 * Le balayage.
 *
 * Trois cibles, et la troisième ne supprime rien — délibérément.
 *
 * @see docs/03-services/storage/05-integration.md
 */
final class SweepStorageCommand extends Command
{
    protected $signature = 'storage:sweep {--dry-run : Compte sans rien effacer}';

    protected $description = 'Efface les déclarations jamais confirmées et les octets des fichiers supprimés.';

    public function handle(DriverRegistry $drivers): int
    {
        $sec = (bool) $this->option('dry-run');
        $orphelins = 0;
        $purges = 0;

        /*
         * Chaque destination est parcourue séparément.
         *
         * Une destination injoignable n'interrompt pas les autres : sans cette
         * séparation, le compte d'un seul client en panne suspendrait le
         * nettoyage de toute la plateforme — un incident local devenu global.
         */
        foreach (Destination::query()->where('environment', app()->environment())->cursor() as $destination) {
            try {
                $driver = $drivers->for($destination);
            } catch (Throwable $e) {
                $this->warn("Destination {$destination->slug} ignorée : {$e->getMessage()}");

                continue;
            }

            $orphelins += $this->sweepOrphans($destination, $driver, $sec);
            $purges += $this->purgeDeleted($destination, $driver, $sec);
        }

        $this->info(sprintf(
            '%s : %d déclaration(s) jamais confirmée(s), %d fichier(s) effacé(s) du magasin.',
            $sec ? 'Simulation' : 'Balayage',
            $orphelins,
            $purges,
        ));

        return self::SUCCESS;
    }

    /**
     * Une déclaration jamais confirmée occupe un objet — donc de l'argent —
     * sans que personne le sache.
     *
     * Le délai est nettement plus long que la durée de l'autorisation
     * d'écriture : un client dont l'écriture a réussi mais dont la confirmation
     * s'est perdue doit avoir le temps de réessayer.
     */
    private function sweepOrphans(Destination $destination, $driver, bool $sec): int
    {
        $seuil = now()->subHours((int) config('storage.orphan_after_hours', 24));
        $compte = 0;

        $query = StoredFile::query()
            ->where('destination_id', $destination->id)
            ->where('status', StoredFile::PENDING)
            ->where('created_at', '<', $seuil);

        foreach ($query->cursor() as $file) {
            $compte++;

            if ($sec) {
                continue;
            }

            try {
                $driver->delete($destination, (string) $file->path);
            } catch (Throwable $e) {
                // Un objet qu'on n'arrive pas à effacer n'empêche pas de marquer
                // la ligne : la relancer au prochain passage est sans danger,
                // l'effacement étant idempotent.
                $this->warn("Effacement impossible pour {$file->id} : {$e->getMessage()}");
            }

            $file->forceFill(['status' => StoredFile::DELETED, 'deleted_at' => now()])->save();
        }

        return $compte;
    }

    /**
     * Les octets d'un fichier supprimé partent après un délai : un `DELETE`
     * accidentel reste réparable pendant ce temps.
     */
    private function purgeDeleted(Destination $destination, $driver, bool $sec): int
    {
        $seuil = now()->subDays((int) config('storage.purge_after_days', 7));
        $compte = 0;

        $query = StoredFile::query()
            ->where('destination_id', $destination->id)
            ->where('status', StoredFile::DELETED)
            ->whereNotNull('deleted_at')
            ->where('deleted_at', '<', $seuil)
            ->whereNull('purged_at');

        foreach ($query->cursor() as $file) {
            $compte++;

            if ($sec) {
                continue;
            }

            try {
                $driver->delete($destination, (string) $file->path);

                // Marquer la purge est ce qui empêche de repasser
                // éternellement sur les mêmes lignes. La ligne, elle, demeure :
                // elle dit qu'un fichier a existé, et l'auditabilité en dépend.
                $file->forceFill(['purged_at' => now()])->save();
            } catch (Throwable $e) {
                $this->warn("Purge impossible pour {$file->id} : {$e->getMessage()}");
            }
        }

        return $compte;
    }
}
