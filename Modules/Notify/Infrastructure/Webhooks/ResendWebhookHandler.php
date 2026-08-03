<?php

declare(strict_types=1);

namespace Modules\Notify\Infrastructure\Webhooks;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Retours de livraison Resend.
 *
 * Resend signe ses webhooks au format Svix : HMAC-SHA256 sur
 * `{id}.{timestamp}.{corps brut}`, la clé étant encodée en base64 derrière le
 * préfixe `whsec_`.
 *
 * @see docs/03-services/notify/03-api.md
 */
final class ResendWebhookHandler implements WebhookHandler
{
    private const TOLERANCE_SECONDS = 300;

    public function provider(): string
    {
        return 'resend';
    }

    public function verify(Request $request): bool
    {
        $secret = (string) config('notify.email.resend.webhook_secret');

        $id = (string) $request->header('svix-id', '');
        $timestamp = (string) $request->header('svix-timestamp', '');
        $signatures = (string) $request->header('svix-signature', '');

        // Sans secret configuré, le endpoint est fermé — jamais ouvert.
        if ($secret === '' || $id === '' || $timestamp === '' || $signatures === '') {
            return false;
        }

        // Sans fenêtre temporelle, une signature valide capturée resterait
        // rejouable indéfiniment.
        if (abs(now()->timestamp - (int) $timestamp) > self::TOLERANCE_SECONDS) {
            return false;
        }

        $key = base64_decode(str_starts_with($secret, 'whsec_') ? substr($secret, 6) : $secret, true);

        if ($key === false) {
            return false;
        }

        // La signature porte sur le corps **brut** : un corps re-sérialisé ne
        // produirait pas le même condensat.
        $expected = base64_encode(
            hash_hmac('sha256', $id.'.'.$timestamp.'.'.$request->getContent(), $key, true)
        );

        // L'en-tête peut contenir plusieurs signatures pendant une rotation de
        // clé : il suffit qu'une seule corresponde.
        foreach (explode(' ', $signatures) as $candidate) {
            $value = str_contains($candidate, ',') ? explode(',', $candidate, 2)[1] : $candidate;

            if (hash_equals($expected, $value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<NormalisedDeliveryEvent>
     */
    public function parse(Request $request): array
    {
        $payload = $request->json()->all();
        $data = (array) ($payload['data'] ?? []);

        $type = match ((string) ($payload['type'] ?? '')) {
            'email.delivered' => 'delivered',
            'email.bounced' => 'bounced',
            'email.complained' => 'complained',
            // `email.sent` n'apporte rien : l'acceptation est déjà connue au
            // moment de l'appel d'envoi. `email.delivery_delayed` est un
            // rebond temporaire, qui ne doit rien supprimer.
            default => null,
        };

        if ($type === null) {
            return [];
        }

        $recipients = (array) ($data['to'] ?? []);

        return [new NormalisedDeliveryEvent(
            type: $type,
            providerMessageId: isset($data['email_id']) ? (string) $data['email_id'] : null,
            // L'identifiant Svix est unique par livraison de webhook : c'est
            // lui qui absorbe les rejeux.
            providerEventId: $request->header('svix-id'),
            destination: $recipients[0] ?? null,
            permanentFailure: $this->isPermanent($type, $data),
            occurredAt: isset($payload['created_at']) ? Carbon::parse($payload['created_at']) : now(),
            payload: ['type' => $payload['type'] ?? null, 'bounce' => $data['bounce'] ?? null],
        )];
    }

    /**
     * Une plainte est définitive. Un rebond ne l'est que si Resend le qualifie
     * de permanent : une boîte pleine se videra peut-être.
     *
     * @param  array<string, mixed>  $data
     */
    private function isPermanent(string $type, array $data): bool
    {
        if ($type === 'complained') {
            return true;
        }

        if ($type !== 'bounced') {
            return false;
        }

        $bounceType = mb_strtolower((string) ($data['bounce']['type'] ?? ''));

        // En l'absence de qualification, on retient le rebond permanent :
        // Resend n'émet `email.bounced` que sur échec définitif, les délais
        // passant par `email.delivery_delayed`.
        return $bounceType !== 'transient';
    }
}
