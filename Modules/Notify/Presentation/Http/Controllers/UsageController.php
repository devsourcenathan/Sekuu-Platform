<?php

declare(strict_types=1);

namespace Modules\Notify\Presentation\Http\Controllers;

use App\Platform\Exceptions\DomainException;
use App\Platform\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Modules\Identity\Domain\AuthenticatedContext;
use Modules\Notify\Application\Sending\SpendGuard;
use Modules\Notify\Domain\Channel;
use Modules\Notify\Domain\Models\Notification;

/**
 * Consommation du mois en cours.
 *
 * Sans cette vue, le coût enregistré à chaque livraison ne servirait à rien :
 * une organisation ne pourrait constater sa dépense qu'après coup, sur la
 * facture du fournisseur.
 *
 * @see docs/03-services/notify/03-api.md
 */
final class UsageController
{
    public function __invoke(AuthenticatedContext $context, SpendGuard $budget): JsonResponse
    {
        $organizationId = $context->token->organizationId
            ?? throw DomainException::forbidden(
                'ORGANIZATION_REQUIRED',
                __('platform.organization_required'),
            );

        $since = now()->startOfMonth();

        $counts = Notification::query()
            ->where('organization_id', $organizationId)
            ->where('created_at', '>=', $since)
            ->selectRaw('channel, status, COUNT(*) as total')
            ->groupBy('channel', 'status')
            ->get();

        $channels = [];

        foreach (Channel::all() as $channel) {
            $forChannel = $counts->where('channel', $channel);
            $limit = $budget->limitFor($channel);
            $spent = $budget->spentThisMonth($channel, $organizationId);

            $channels[$channel] = [
                'sent' => (int) $forChannel->sum('total'),
                'by_status' => $forChannel->pluck('total', 'status')->map(fn ($n) => (int) $n)->all(),
                'cost' => [
                    'spent' => round($spent, 4),
                    'limit' => $limit,
                    // `null` lorsque aucun plafond n'est configuré : c'est une
                    // absence de contrôle, pas un budget infini mesuré.
                    'remaining' => $limit === null ? null : round(max(0, $limit - $spent), 4),
                    'currency' => $budget->currency(),
                ],
            ];
        }

        return ApiResponse::success([
            'period' => [
                'from' => $since->toIso8601ZuluString(),
                'to' => now()->toIso8601ZuluString(),
            ],
            'channels' => $channels,
            'purged' => $this->purgedTotals($organizationId, $since),
        ]);
    }

    /**
     * Les notifications purgées ne sont plus comptables ligne à ligne, mais
     * leur agrégat survit : sans lui, la consommation d'un mois ancien
     * paraîtrait nulle.
     *
     * @return array<string, int>
     */
    private function purgedTotals(string $organizationId, \DateTimeInterface $since): array
    {
        return DB::table('notification_statistics')
            ->where('organization_id', $organizationId)
            ->where('day', '>=', $since)
            ->selectRaw('channel, SUM(total) as total')
            ->groupBy('channel')
            ->pluck('total', 'channel')
            ->map(fn ($n) => (int) $n)
            ->all();
    }
}
