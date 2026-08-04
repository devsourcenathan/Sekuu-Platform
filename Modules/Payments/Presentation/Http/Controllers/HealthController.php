<?php

declare(strict_types=1);

namespace Modules\Payments\Presentation\Http\Controllers;

use App\Platform\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Modules\Payments\Infrastructure\Providers\PaymentProvider;
use Modules\Payments\Infrastructure\Providers\ProviderRegistry;

/**
 * Indique quels agrégateurs sont réellement configurés.
 *
 * Un agrégateur sans identifiants n'est jamais essayé : le dire franchement
 * évite de croire qu'un paiement pourra partir alors que rien n'est branché.
 */
final class HealthController
{
    public function __invoke(ProviderRegistry $registry): JsonResponse
    {
        $configured = array_map(
            static fn (PaymentProvider $provider): string => $provider->name(),
            $registry->all(),
        );

        return ApiResponse::success([
            'status' => $configured === [] ? 'degraded' : 'ok',
            'providers' => $configured,

            // Sans agrégateur configuré, aucun paiement ne peut aboutir. Le
            // service répond, mais il n'encaisse pas — et sur un modèle
            // prépayé, ne plus encaisser ferme les accès un par un.
            'can_collect' => $configured !== [],

            'currency' => config('sekuu.default_currency'),
        ]);
    }
}
