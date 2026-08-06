<?php

declare(strict_types=1);

namespace Modules\AI\Application\Generation;

use App\Platform\Exceptions\DomainException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Modules\AI\Domain\Models\AiGeneration;
use Throwable;

/**
 * L'appel au fournisseur, hors du fil de la requête.
 *
 * ## Le prompt voyage dans la charge du travail, et c'est le seul endroit
 *
 * L'ADR-0016 refuse de constituer un registre de prompts. Une file en garde un
 * le temps du traitement — c'est irréductible pour de l'asynchrone, et
 * différent d'un registre sur trois points : la ligne est **supprimée à la fin
 * du travail**, elle n'est pas indexée, et elle n'est consultable par aucune
 * route.
 *
 * L'alternative aurait été d'écrire l'entrée dans `ai_contents`, ce qui aurait
 * produit exactement le registre qu'on refuse — et durable, lui.
 *
 * ## Un seul essai
 *
 * Un travail qui se relance relance une **génération payante**. La file n'a pas
 * de quoi décider si le premier appel a atteint le modèle ; `RunTask` le sait, et
 * a déjà fait sa bascule. Réessayer par-dessus paierait une seconde fois ce que
 * la règle de bascule vient de refuser de payer.
 *
 * @see docs/04-decisions/adr-0016-ai-spend-and-privacy.md
 */
final class RunTaskJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** Voir plus haut : un réessai de file est une génération de plus. */
    public int $tries = 1;

    public function __construct(
        public readonly string $generationId,
        public readonly TaskRequest $request,
    ) {}

    public function handle(RunTask $runner): void
    {
        try {
            $runner->resume($this->generationId, $this->request);
        } catch (DomainException $e) {
            /*
             * Les gardes ont déjà été passées à l'ouverture ; arriver ici
             * signifie que quelque chose a changé entre-temps — un compte mis en
             * pause, un quota franchi par une autre génération.
             *
             * On l'écrit sur la ligne plutôt que de laisser le travail échouer :
             * l'appelant sonde cette ligne, et un travail en échec ne lui dit
             * rien.
             */
            $this->settle($e->errorCode, $e->getMessage());
        }
    }

    /**
     * Le travail est mort autrement — mémoire, arrêt du travailleur, panne.
     *
     * Sans ceci, la demande resterait `queued` pour toujours, et l'appelant
     * sonderait indéfiniment une ligne que plus personne ne reprendra.
     */
    public function failed(Throwable $exception): void
    {
        $this->settle('AI_PROVIDER_ERROR', $exception->getMessage());

        Log::error('Génération abandonnée par la file.', [
            'generation_id' => $this->generationId,
            'error' => mb_substr($exception->getMessage(), 0, 500),
        ]);
    }

    private function settle(string $code, string $reason): void
    {
        $generation = AiGeneration::query()->find($this->generationId);

        // Déjà conclue par `RunTask` : il en sait plus que nous, on ne
        // réécrit pas par-dessus.
        if ($generation === null || $generation->isSettled()) {
            return;
        }

        $generation->forceFill([
            'status' => AiGeneration::FAILED,
            'failure_code' => $code,
            'failure_reason' => mb_substr($reason, 0, 2000),
            'completed_at' => now(),
        ])->save();
    }
}
