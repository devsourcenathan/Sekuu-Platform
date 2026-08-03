<?php

declare(strict_types=1);

namespace Modules\Notify\Infrastructure\Providers;

use Illuminate\Support\Facades\Http;
use Modules\Notify\Domain\Channel;
use Modules\Notify\Domain\Models\Notification;
use Throwable;

/**
 * Fournisseur email transactionnel.
 *
 * C'est lui qui ferme la boucle des rebonds : le mailer Laravel envoie mais ne
 * rapporte rien, donc la liste de suppression ne s'alimenterait jamais.
 *
 * @see docs/03-services/notify/01-overview.md
 */
final class ResendMailProvider implements MessageProvider
{
    private const ENDPOINT = 'https://api.resend.com/emails';

    public function name(): string
    {
        return 'resend';
    }

    public function channel(): string
    {
        return Channel::EMAIL;
    }

    public function isConfigured(): bool
    {
        return (string) config('notify.email.resend.api_key') !== '';
    }

    public function send(Notification $notification): ProviderResult
    {
        $config = (array) config('notify.email.resend');

        try {
            $response = Http::withToken((string) $config['api_key'])
                ->timeout((int) ($config['timeout'] ?? 10))
                ->acceptJson()
                ->post(self::ENDPOINT, [
                    'from' => $config['from'] ?? config('mail.from.address'),
                    'to' => [$notification->recipient],
                    'subject' => (string) $notification->rendered_subject,
                    'html' => $notification->rendered_body,
                    // Renvoyés dans les webhooks : le rapprochement ne dépend
                    // donc pas du seul identifiant Resend.
                    'headers' => array_filter([
                        'X-Sekuu-Notification-Id' => $notification->id,
                        'X-Request-Id' => $notification->request_id,
                    ]),
                    // Resend impose des étiquettes alphanumériques : la clé de
                    // template contient un point, on le remplace.
                    'tags' => [
                        ['name' => 'template', 'value' => str_replace('.', '_', (string) $notification->template_key)],
                        ['name' => 'category', 'value' => (string) $notification->category],
                    ],
                ]);
        } catch (Throwable $e) {
            return ProviderResult::failed('PROVIDER_ERROR', $e->getMessage());
        }

        if ($response->successful()) {
            return ProviderResult::accepted(messageId: (string) $response->json('id'));
        }

        return $this->interpret(
            $response->status(),
            (string) ($response->json('name') ?? ''),
            (string) ($response->json('message') ?? ''),
        );
    }

    private function interpret(int $status, string $name, string $message): ProviderResult
    {
        // 5xx : panne côté fournisseur, la bascule a un sens.
        if ($status >= 500) {
            return ProviderResult::failed('PROVIDER_ERROR', $message !== '' ? $message : 'Resend returned '.$status);
        }

        // Quota atteint : réessayable, mais pas rattrapable par un autre
        // fournisseur du même compte. Le backoff de la file s'en charge.
        if ($status === 429 || $name === 'rate_limit_exceeded' || $name === 'daily_quota_exceeded') {
            return ProviderResult::failed('QUOTA_EXCEEDED', $message);
        }

        // Adresse rejetée à la validation : elle ne deviendra pas valide.
        if ($name === 'validation_error' && str_contains(mb_strtolower($message), 'email')) {
            return ProviderResult::rejected('RECIPIENT_INVALID', $message)
                ->suppressingDestination();
        }

        // 401/403 : clé absente ou révoquée. C'est un problème de
        // configuration, pas de destinataire — surtout ne pas supprimer
        // l'adresse pour autant.
        return ProviderResult::rejected('PROVIDER_ERROR', $message !== '' ? $message : $name);
    }
}
