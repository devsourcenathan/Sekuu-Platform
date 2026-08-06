<?php

declare(strict_types=1);

namespace Modules\AI\Application\Generation;

use App\Platform\Http\RequestId;
use Illuminate\Support\Str;
use Modules\AI\Domain\Models\AiDelivery;
use Modules\AI\Domain\Models\AiEndpoint;
use Modules\AI\Infrastructure\External\DeliverAiEvent;

/**
 * Enfile une issue vers le produit qui l'attend.
 *
 * ## Elle n'écrit qu'une ligne
 *
 * Aucun appel réseau ici : un produit lent tiendrait sinon le fil d'exécution
 * d'une génération, pendant que ses jetons sont déjà payés.
 *
 * ## Un produit sans destination n'est pas une erreur
 *
 * Il lui reste le sondage, qui est de toute façon la voie normale — une
 * génération perdue a déjà coûté et n'existe nulle part ailleurs. Mais le taire
 * complètement laisserait une intégration à moitié faite passer pour terminée,
 * donc l'absence de destination est simplement sans effet et sans bruit.
 *
 * @see docs/03-services/ai/07-external-api.md
 */
final class AnnounceOutcome
{
    public const SUCCEEDED = 'ai.generation.succeeded';

    public const FAILED = 'ai.generation.failed';

    public const THRESHOLD = 'ai.spend.threshold_reached';

    public const ACCOUNT_UNVERIFIED = 'ai.account.unverified';

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(?string $organizationId, string $eventType, array $data, ?string $generationId = null): void
    {
        if ($organizationId === null) {
            return;
        }

        $endpoint = AiEndpoint::query()->where('organization_id', $organizationId)->first();

        if ($endpoint === null) {
            return;
        }

        // Stable d'un réessai à l'autre : c'est la clé sur laquelle le produit
        // déduplique, et une clé qui changerait à chaque envoi rendrait la
        // déduplication impossible.
        $eventId = 'evt_'.Str::lower((string) Str::ulid());

        $delivery = AiDelivery::query()->create([
            'ai_endpoint_id' => $endpoint->id,
            'event_id' => $eventId,
            'event_type' => $eventType,
            'generation_id' => $generationId,
            'payload' => $this->envelope($eventId, $eventType, $data),
            'status' => AiDelivery::PENDING,
        ]);

        // `afterCommit` : sans lui, un worker peut lire la ligne avant que la
        // transaction ne soit validée, et livrer l'issue d'une génération que la
        // base ne connaît pas encore.
        DeliverAiEvent::dispatch($delivery->id)->afterCommit();
    }

    /**
     * L'enveloppe est identique quel que soit l'événement : un intégrateur
     * n'apprend qu'une seule forme, et c'est la même que côté paiement.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function envelope(string $eventId, string $eventType, array $data): array
    {
        return [
            'id' => $eventId,
            'type' => $eventType,
            'occurred_at' => now()->toIso8601ZuluString(),
            'request_id' => RequestId::current(),
            'data' => $data,
        ];
    }
}
