<?php

declare(strict_types=1);

namespace Modules\Notify\Infrastructure\Providers;

use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Mail;
use Modules\Notify\Domain\Channel;
use Modules\Notify\Domain\Models\Notification;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Throwable;

/**
 * Fournisseur email s'appuyant sur le mailer configuré de Laravel.
 *
 * Il couvre le développement (driver `log`) et une production simple (SMTP,
 * SES). Un fournisseur transactionnel dédié — Postmark, Resend — s'ajoutera
 * comme une seconde implémentation, sans toucher au domaine.
 */
final class LaravelMailProvider implements MessageProvider
{
    public function name(): string
    {
        return 'laravel-mail';
    }

    public function channel(): string
    {
        return Channel::EMAIL;
    }

    /**
     * Le mailer de Laravel est toujours disponible : il sert de dernier
     * recours, y compris sur le driver `log` en développement.
     */
    public function isConfigured(): bool
    {
        return true;
    }

    public function send(Notification $notification): ProviderResult
    {
        try {
            Mail::html($notification->rendered_body, function (Message $message) use ($notification): void {
                $message->to($notification->recipient)
                    ->subject((string) $notification->rendered_subject);

                // Permet de rapprocher un retour fournisseur de la notification,
                // et de tracer le message jusqu'à la requête d'origine.
                $message->getHeaders()->addTextHeader('X-Sekuu-Notification-Id', $notification->id);

                if ($notification->request_id !== null) {
                    $message->getHeaders()->addTextHeader('X-Request-Id', $notification->request_id);
                }
            });

            return ProviderResult::accepted(messageId: $notification->id);
        } catch (TransportExceptionInterface $e) {
            // Panne de transport : réessayable, et éligible à la bascule.
            return ProviderResult::failed('PROVIDER_ERROR', $e->getMessage());
        } catch (Throwable $e) {
            // Tout le reste vient du message lui-même : le réessayer est vain.
            return ProviderResult::rejected('PROVIDER_ERROR', $e->getMessage());
        }
    }
}
