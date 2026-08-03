<?php

declare(strict_types=1);

namespace Modules\Notify\Presentation\Http\Controllers;

use App\Platform\Exceptions\DomainException;
use App\Platform\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Modules\Identity\Infrastructure\Auth\ApiKeyResolver;
use Modules\Notify\Domain\Models\Suppression;
use Modules\Notify\Presentation\Http\Requests\CreateSuppressionRequest;

/**
 * Liste de suppression.
 *
 * Une suppression est **globale à la plateforme**, pas propre à une
 * organisation : une adresse morte l'est pour tout le monde, et la réputation
 * d'expédition qu'elle protège est celle du domaine entier.
 *
 * C'est pourquoi ces routes exigent le scope `notifications.manage`, réservé
 * aux clés d'exploitation de la plateforme. L'accorder à une organisation
 * cliente lui permettrait de lever une suppression qui protège les autres.
 *
 * @see docs/04-decisions/adr-0006-transactional-vs-marketing.md
 */
final class SuppressionController
{
    public function __construct(private readonly ApiKeyResolver $keys) {}

    public function index(Request $request): JsonResponse
    {
        $this->keys->require($request, 'notifications.read');

        $query = Suppression::query()->orderByDesc('created_at')->orderByDesc('id');

        $this->applyFilters($request, $query);

        $paginator = $query->cursorPaginate($this->perPage($request));

        return ApiResponse::success(
            $paginator->getCollection()->map($this->present(...))->all(),
            [
                'per_page' => $paginator->perPage(),
                'next_cursor' => $paginator->nextCursor()?->encode(),
                'has_more' => $paginator->hasMorePages(),
            ],
        );
    }

    public function store(CreateSuppressionRequest $request): JsonResponse
    {
        $context = $this->keys->require($request, 'notifications.manage');

        $destination = Suppression::normalise($request->string('destination')->toString());
        $channel = $request->string('channel')->toString();

        $existing = Suppression::query()
            ->where('channel', $channel)
            ->where('destination', $destination)
            ->whereNull('expires_at')
            ->first();

        if ($existing !== null) {
            throw DomainException::conflict(
                'DUPLICATE_RESOURCE',
                __('notify::messages.suppression_already_exists'),
            );
        }

        $suppression = Suppression::create([
            'channel' => $channel,
            'destination' => $destination,
            // Une suppression posée à la main est toujours `manual` : les
            // autres motifs constatent un fait rapporté par un fournisseur.
            'reason' => Suppression::MANUAL,
            'source' => 'api:'.$context->key->prefix,
            'expires_at' => $request->input('expires_at'),
        ]);

        return ApiResponse::created($this->present($suppression));
    }

    /**
     * Réhabilitation. Action sensible : réhabiliter une adresse qui rebondit
     * durablement dégrade la réputation de **tout le domaine**, pas seulement
     * celle de l'organisation concernée.
     */
    public function destroy(Request $request, string $suppressionId): JsonResponse
    {
        $context = $this->keys->require($request, 'notifications.manage');

        $suppression = Suppression::query()->whereKey($suppressionId)->first();

        if ($suppression === null) {
            throw DomainException::notFound(
                'SUPPRESSION_NOT_FOUND',
                __('notify::messages.suppression_not_found'),
            );
        }

        // Journalisé sans exception : c'est la seule trace d'une décision qui
        // engage la délivrabilité de la plateforme.
        Log::warning('Réhabilitation d\'une destination supprimée.', [
            'channel' => $suppression->channel,
            'destination' => $suppression->destination,
            'reason' => $suppression->reason,
            'source' => $suppression->source,
            'api_key' => $context->key->prefix,
            'organization_id' => $context->organizationId(),
        ]);

        $suppression->delete();

        return ApiResponse::noContent();
    }

    private function applyFilters(Request $request, $query): void
    {
        $filters = $request->query('filter', []);

        if (! is_array($filters)) {
            throw new DomainException('INVALID_FILTER', __('platform.filter_malformed'), 400);
        }

        foreach ($filters as $field => $value) {
            match ($field) {
                'channel', 'reason' => $query->where($field, $value),
                // Recherche partielle : on cherche souvent une adresse dont on
                // ne se rappelle qu'un fragment.
                'destination' => $query->where('destination', 'like', '%'.Suppression::normalise((string) $value).'%'),
                default => throw new DomainException(
                    'INVALID_FILTER',
                    __('platform.filter_unknown', ['field' => (string) $field]),
                    400,
                ),
            };
        }
    }

    private function perPage(Request $request): int
    {
        $perPage = (int) $request->query('per_page', (string) config('sekuu.pagination.per_page'));

        return max(1, min($perPage, (int) config('sekuu.pagination.max_per_page')));
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Suppression $suppression): array
    {
        return [
            'id' => $suppression->id,
            'channel' => $suppression->channel,
            'destination' => $suppression->destination,
            'reason' => $suppression->reason,
            'source' => $suppression->source,
            'expires_at' => $suppression->expires_at?->toIso8601ZuluString(),
            'created_at' => $suppression->created_at?->toIso8601ZuluString(),
        ];
    }
}
