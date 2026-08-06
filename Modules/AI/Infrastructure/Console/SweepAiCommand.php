<?php

declare(strict_types=1);

namespace Modules\AI\Infrastructure\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Modules\AI\Domain\Models\AiContent;
use Modules\AI\Domain\Models\AiGeneration;

/**
 * Le balayage : ce qui a expiré, et ce qui n'a jamais conclu.
 *
 * Deux cibles, et elles n'ont rien en commun sinon d'être invisibles.
 *
 * @see docs/03-services/ai/02-data-model.md
 */
final class SweepAiCommand extends Command
{
    protected $signature = 'ai:sweep {--dry-run : Compte sans rien effacer}';

    protected $description = 'Efface les contenus expirés et conclut les générations abandonnées.';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');

        $expired = $this->purgeExpiredContent($dry);
        $abandoned = $this->settleAbandoned($dry);

        $this->info(sprintf(
            '%s : %d contenu(s) effacé(s), %d génération(s) abandonnée(s) conclue(s).',
            $dry ? 'Simulation' : 'Balayage',
            $expired,
            $abandoned,
        ));

        return self::SUCCESS;
    }

    /**
     * **L'effacement n'est pas optionnel.**
     *
     * Une durée de conservation qui ne serait pas appliquée est une promesse
     * fausse, et c'est le genre de promesse qu'on découvre fausse lors d'un
     * audit — pas avant.
     *
     * Deux populations passent par ici : les sorties non conservées, dont la
     * fenêtre de lecture est courte, et les contenus dont une tâche a déclaré la
     * rétention. Le même geste, parce que `expires_at` porte déjà la
     * distinction.
     */
    private function purgeExpiredContent(bool $dry): int
    {
        $query = AiContent::query()->where('expires_at', '<', now());

        $count = $query->count();

        if (! $dry && $count > 0) {
            $query->delete();
        }

        return $count;
    }

    /**
     * Les générations que plus personne ne reprendra.
     *
     * ## Pourquoi ce filet existe malgré `RunTaskJob::failed()`
     *
     * Ce crochet couvre un travail qui échoue ; il ne couvre pas un travailleur
     * **tué net** — mémoire épuisée, conteneur redémarré, machine arrêtée. La
     * ligne reste alors `queued` ou `running` pour toujours, et l'appelant sonde
     * indéfiniment quelque chose qui ne bougera plus.
     *
     * ## Le coût reste inconnu, et on le dit
     *
     * `cost_micros` n'est pas mis à zéro : la requête est peut-être partie et a
     * peut-être été facturée. Écrire zéro donnerait un total qui ne correspond
     * pas à la facture du fournisseur, et l'écart ne se verrait qu'en fin de
     * mois. `null` dit « on ne sait pas », ce qui est la vérité.
     */
    private function settleAbandoned(bool $dry): int
    {
        $threshold = now()->subMinutes((int) config('ai.abandoned_after_minutes', 60));

        $query = AiGeneration::query()
            ->whereIn('status', [AiGeneration::QUEUED, AiGeneration::RUNNING])
            ->where('created_at', '<', $threshold);

        $count = 0;

        foreach ($query->cursor() as $generation) {
            $count++;

            if ($dry) {
                continue;
            }

            $generation->forceFill([
                'status' => AiGeneration::FAILED,
                'failure_code' => 'AI_ABANDONED',
                'failure_reason' => 'Aucun travailleur n\'a conclu cette génération.',
                'completed_at' => now(),
            ])->save();

            /*
             * Journalisé une par une, et en `warning`.
             *
             * Une génération abandonnée est le signe qu'un travailleur meurt —
             * mémoire, redémarrage, arrêt. Un compteur agrégé dirait qu'il y en
             * a eu douze ; il ne dirait pas lesquelles, ni de quelle tâche, et
             * c'est ce qui permet de trouver la cause.
             */
            Log::warning('ai: génération abandonnée, conclue par le balayage', [
                'generation_id' => (string) $generation->id,
                'organization_id' => $generation->organization_id,
                'task' => $generation->task,
                'status' => $generation->getOriginal('status'),
                'created_at' => $generation->created_at?->toIso8601String(),
            ]);
        }

        return $count;
    }
}
