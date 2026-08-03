<?php

declare(strict_types=1);

namespace Modules\Notify\Infrastructure\Webhooks;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Retours de livraison email au format Postmark.
 *
 * Postmark n'expose pas de signature HMAC : l'authentification se fait par
 * Basic Auth sur l'URL de webhook, avec des identifiants dédiés. C'est plus
 * faible qu'une signature, d'où la comparaison en temps constant et l'exigence
 * d'un secret configuré — sans quoi le webhook est refusé plutôt qu'ouvert.
 */
final class PostmarkWebhookHandler implements WebhookHandler
{
    public function provider(): string
    {
        return 'postmark';
    }

    public function verify(Request $request): bool
    {
        $expected = (string) config('notify.email.postmark.webhook_token');

        if ($expected === '') {
            return false;
        }

        return hash_equals($expected, (string) $request->header('X-Webhook-Token', ''));
    }

    /**
     * @return list<NormalisedDeliveryEvent>
     */
    public function parse(Request $request): array
    {
        $item = $request->json()->all();
        $record = (string) ($item['RecordType'] ?? '');

        $type = match ($record) {
            'Delivery' => 'delivered',
            'Bounce' => 'bounced',
            'SpamComplaint' => 'complained',
            'SubscriptionChange' => 'unsubscribed',
            default => null,
        };

        if ($type === null) {
            return [];
        }

        // Postmark distingue les rebonds durs (HardBounce) des temporaires
        // (SoftBounce, Transient) : seuls les premiers suppriment l'adresse.
        $permanent = $record === 'SpamComplaint'
            || in_array((string) ($item['Type'] ?? ''), ['HardBounce', 'BadEmailAddress', 'Blocked'], true);

        return [new NormalisedDeliveryEvent(
            type: $type,
            providerMessageId: isset($item['MessageID']) ? (string) $item['MessageID'] : null,
            providerEventId: isset($item['ID']) ? (string) $item['ID'] : null,
            destination: isset($item['Email']) ? (string) $item['Email'] : ($item['Recipient'] ?? null),
            permanentFailure: $permanent,
            occurredAt: isset($item['BouncedAt']) || isset($item['DeliveredAt'])
                ? Carbon::parse($item['BouncedAt'] ?? $item['DeliveredAt'])
                : now(),
            payload: ['record_type' => $record, 'type' => $item['Type'] ?? null],
        )];
    }
}
