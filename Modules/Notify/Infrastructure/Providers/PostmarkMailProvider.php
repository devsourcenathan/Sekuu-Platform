<?php

declare(strict_types=1);

namespace Modules\Notify\Infrastructure\Providers;

use Illuminate\Support\Facades\Http;
use Modules\Notify\Domain\Category;
use Modules\Notify\Domain\Channel;
use Modules\Notify\Domain\Models\Notification;
use Throwable;

/**
 * Fournisseur email transactionnel.
 *
 * C'est lui qui ferme la boucle des rebonds : le mailer Laravel envoie mais ne
 * rapporte rien, donc la liste de suppression ne s'alimentait jamais.
 *
 * @see docs/03-services/notify/03-api.md
 */
final class PostmarkMailProvider implements MessageProvider
{
    private const ENDPOINT = 'https://api.postmarkapp.com/email';

    /** Adresse déjà connue comme morte par Postmark : rebond dur ou plainte. */
    private const INACTIVE_RECIPIENT = 406;

    private const INVALID_RECIPIENT = 300;

    public function name(): string
    {
        return 'postmark';
    }

    public function channel(): string
    {
        return Channel::EMAIL;
    }

    public function isConfigured(): bool
    {
        return (string) config('notify.email.postmark.server_token') !== '';
    }

    public function send(Notification $notification): ProviderResult
    {
        $config = (array) config('notify.email.postmark');

        try {
            $response = Http::withHeaders([
                'X-Postmark-Server-Token' => (string) $config['server_token'],
            ])
                ->timeout((int) ($config['timeout'] ?? 10))
                ->acceptJson()
                ->post(self::ENDPOINT, [
                    'From' => $config['from'] ?? config('mail.from.address'),
                    'To' => $notification->recipient,
                    'Subject' => (string) $notification->rendered_subject,
                    'HtmlBody' => $notification->rendered_body,
                    'MessageStream' => $this->streamFor($notification),
                    // Renvoyées telles quelles dans les webhooks : le
                    // rapprochement ne dépend donc pas du seul MessageID.
                    'Metadata' => array_filter([
                        'notification_id' => $notification->id,
                        'request_id' => $notification->request_id,
                    ]),
                ]);
        } catch (Throwable $e) {
            return ProviderResult::failed('PROVIDER_ERROR', $e->getMessage());
        }

        if ($response->successful()) {
            return ProviderResult::accepted(messageId: (string) $response->json('MessageID'));
        }

        return $this->interpret($response->status(), (int) $response->json('ErrorCode'), (string) $response->json('Message'));
    }

    private function interpret(int $status, int $errorCode, string $message): ProviderResult
    {
        // Postmark a déjà constaté que cette adresse ne reçoit plus. Continuer
        // à lui écrire dégraderait la réputation du domaine : on l'inscrit
        // localement sans attendre un webhook qui ne viendra pas.
        if ($errorCode === self::INACTIVE_RECIPIENT) {
            return ProviderResult::rejected('RECIPIENT_SUPPRESSED', $message)
                ->suppressingDestination();
        }

        if ($errorCode === self::INVALID_RECIPIENT) {
            return ProviderResult::rejected('RECIPIENT_INVALID', $message)
                ->suppressingDestination();
        }

        // 5xx : panne côté fournisseur, la bascule a un sens.
        if ($status >= 500) {
            return ProviderResult::failed('PROVIDER_ERROR', $message !== '' ? $message : 'Postmark returned '.$status);
        }

        // 401 ou 422 : la requête est en cause. Réessayer ne changera rien.
        return ProviderResult::rejected('PROVIDER_ERROR', $message);
    }

    /**
     * Postmark exige que les envois de masse passent par un flux dédié :
     * les mélanger dégrade la réputation du flux transactionnel, celui dont
     * dépendent les liens de réinitialisation.
     */
    private function streamFor(Notification $notification): string
    {
        $config = (array) config('notify.email.postmark');

        return $notification->category === Category::MARKETING
            ? (string) ($config['broadcast_stream'] ?? 'broadcast')
            : (string) ($config['transactional_stream'] ?? 'outbound');
    }
}
