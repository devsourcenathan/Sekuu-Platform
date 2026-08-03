<?php

declare(strict_types=1);

namespace Modules\Notify\Presentation\Http\Controllers;

use App\Platform\Exceptions\DomainException;
use App\Platform\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Notify\Application\Delivery\RecordDeliveryEvent;
use Modules\Notify\Infrastructure\Webhooks\WebhookRegistry;

/**
 * Retours de livraison des fournisseurs.
 *
 * Publique au sens réseau, authentifiée par signature.
 *
 * @see docs/03-services/notify/03-api.md
 */
final class WebhookController
{
    public function __invoke(
        Request $request,
        WebhookRegistry $registry,
        RecordDeliveryEvent $recorder,
        string $provider,
    ): JsonResponse {
        $handler = $registry->for($provider);

        if (! $handler->verify($request)) {
            throw new DomainException(
                'WEBHOOK_SIGNATURE_INVALID',
                __('notify::messages.webhook_signature_invalid'),
                401,
            );
        }

        $processed = 0;

        foreach ($handler->parse($request) as $event) {
            if ($recorder->handle($provider, $event) !== null) {
                $processed++;
            }
        }

        // Toujours 200, y compris pour un événement inconnu ou déjà traité :
        // répondre en erreur déclencherait des réessais inutiles chez le
        // fournisseur, et finirait par faire désactiver le endpoint.
        return ApiResponse::success(['processed' => $processed]);
    }
}
