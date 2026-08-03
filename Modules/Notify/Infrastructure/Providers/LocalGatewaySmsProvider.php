<?php

declare(strict_types=1);

namespace Modules\Notify\Infrastructure\Providers;

use Illuminate\Support\Facades\Http;
use Modules\Notify\Domain\Channel;
use Modules\Notify\Domain\Models\Notification;
use Throwable;

/**
 * Passerelle SMS d'un agrégateur ou d'un opérateur local.
 *
 * Ces passerelles n'ont pas de standard, mais exposent presque toutes la même
 * forme : un POST JSON avec destinataire et texte, authentifié par jeton. Le
 * mapping des champs est donc configurable plutôt que codé en dur.
 *
 * Sur les marchés visés, cet acheminement est moins cher et mieux délivré qu'un
 * envoi international : c'est le fournisseur de premier rang, pas un repli.
 */
final class LocalGatewaySmsProvider implements MessageProvider
{
    public function name(): string
    {
        return 'local-gateway';
    }

    public function channel(): string
    {
        return Channel::SMS;
    }

    public function isConfigured(): bool
    {
        $config = (array) config('notify.sms.local_gateway');

        return ! empty($config['endpoint']) && ! empty($config['token']);
    }

    public function send(Notification $notification): ProviderResult
    {
        $config = (array) config('notify.sms.local_gateway');

        try {
            $response = Http::withToken($config['token'])
                ->timeout((int) ($config['timeout'] ?? 10))
                ->asJson()
                ->post($config['endpoint'], [
                    'to' => $notification->recipient,
                    // Un SMS n'a pas de mise en forme : le corps rendu est
                    // envoyé tel quel, sans sujet.
                    'message' => $notification->rendered_body,
                    'sender' => $config['sender_id'] ?? 'SEKUU',
                    'reference' => $notification->id,
                ]);
        } catch (Throwable $e) {
            return ProviderResult::failed('PROVIDER_ERROR', $e->getMessage());
        }

        if ($response->successful()) {
            return ProviderResult::accepted(
                messageId: (string) ($response->json('message_id') ?? $notification->id),
                cost: $response->json('cost') === null ? null : (float) $response->json('cost'),
                currency: $response->json('currency'),
            );
        }

        // 4xx : la passerelle a compris et refusé — numéro hors réseau, contenu
        // interdit. Réessayer ou basculer ne changerait rien.
        if ($response->clientError()) {
            return ProviderResult::rejected(
                'PROVIDER_ERROR',
                (string) ($response->json('error') ?? $response->body()),
            );
        }

        return ProviderResult::failed('PROVIDER_ERROR', 'Gateway returned '.$response->status());
    }
}
