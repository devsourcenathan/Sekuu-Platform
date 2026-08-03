<?php

declare(strict_types=1);

namespace Modules\Notify\Infrastructure\Webhooks;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Accusés de réception (DLR) de la passerelle SMS locale.
 *
 * Signature HMAC-SHA256 sur le corps brut, avec horodatage : c'est le schéma
 * le plus courant chez les agrégateurs, et le seul qui protège du rejeu.
 */
final class LocalGatewayWebhookHandler implements WebhookHandler
{
    private const TOLERANCE_SECONDS = 300;

    public function provider(): string
    {
        return 'local-gateway';
    }

    public function verify(Request $request): bool
    {
        $secret = (string) config('notify.sms.local_gateway.webhook_secret');
        $header = (string) $request->header('X-Gateway-Signature', '');

        if ($secret === '' || $header === '') {
            return false;
        }

        [$timestamp, $signature] = array_pad(explode(',', $header, 2), 2, null);

        if ($timestamp === null || $signature === null) {
            return false;
        }

        // Sans fenêtre temporelle, une signature valide capturée reste
        // rejouable indéfiniment.
        if (abs(now()->timestamp - (int) $timestamp) > self::TOLERANCE_SECONDS) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$request->getContent(), $secret);

        return hash_equals($expected, $signature);
    }

    /**
     * @return list<NormalisedDeliveryEvent>
     */
    public function parse(Request $request): array
    {
        $events = [];

        // Les passerelles envoient tantôt un événement, tantôt un lot.
        $items = $request->json('events') ?? [$request->json()->all()];

        foreach ($items as $item) {
            $status = mb_strtolower((string) ($item['status'] ?? ''));

            $type = match ($status) {
                'delivered', 'delivrd' => 'delivered',
                'undeliverable', 'undeliv', 'rejected', 'expired' => 'bounced',
                default => null,
            };

            if ($type === null) {
                continue;
            }

            $events[] = new NormalisedDeliveryEvent(
                type: $type,
                providerMessageId: isset($item['message_id']) ? (string) $item['message_id'] : null,
                providerEventId: isset($item['event_id']) ? (string) $item['event_id'] : null,
                destination: isset($item['to']) ? (string) $item['to'] : null,
                // Un numéro qui n'existe pas ne se mettra pas à exister :
                // contrairement à une boîte pleine, c'est définitif.
                permanentFailure: in_array($status, ['undeliverable', 'undeliv', 'rejected'], true),
                occurredAt: isset($item['occurred_at']) ? Carbon::parse($item['occurred_at']) : now(),
                payload: is_array($item) ? $item : [],
            );
        }

        return $events;
    }
}
