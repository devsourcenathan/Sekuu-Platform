<?php

declare(strict_types=1);

namespace Modules\Notify\Application\Delivery;

use App\Platform\Events\DomainEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Notify\Domain\Models\Notification;
use Modules\Notify\Domain\Models\NotificationDelivery;
use Modules\Notify\Domain\Models\Suppression;
use Modules\Notify\Infrastructure\Providers\ProviderRegistry;
use Modules\Notify\Infrastructure\Providers\ProviderResult;

/**
 * Livre une notification chez un fournisseur.
 *
 * Bascule après un échec **infrastructurel** ; un rejet métier arrête
 * immédiatement — un numéro invalide ne devient pas valide chez un autre
 * opérateur.
 *
 * @see docs/03-services/notify/04-events.md
 */
final class DeliverNotification implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** 1 min, 5 min, 30 min, 2 h, 6 h. */
    public array $backoff = [60, 300, 1800, 7200, 21600];

    public int $tries = 5;

    public function __construct(public readonly string $notificationId) {}

    /**
     * Un même message ne doit pas être livré deux fois si la tâche est
     * dupliquée par la file.
     */
    public function uniqueId(): string
    {
        return $this->notificationId;
    }

    public function handle(ProviderRegistry $registry, SuppressDestination $suppressor): void
    {
        $notification = Notification::query()->find($this->notificationId);

        if ($notification === null || $notification->status !== Notification::QUEUED) {
            return;
        }

        $notification->forceFill(['status' => Notification::SENDING])->save();

        $attempt = (int) $notification->deliveries()->max('attempt');
        $lastResult = null;

        foreach ($registry->forChannel($notification->channel) as $provider) {
            $attempt++;
            $lastResult = $provider->send($notification);

            $this->recordAttempt($notification, $provider->name(), $attempt, $lastResult);

            if ($lastResult->accepted) {
                $this->markSent($notification);

                return;
            }

            // Le fournisseur sait que cette destination ne reçoit plus : rien
            // ne remontera par webhook, il faut l'inscrire maintenant.
            if ($lastResult->suppressesDestination) {
                $suppressor->handle(
                    channel: $notification->channel,
                    destination: $notification->recipient,
                    reason: $lastResult->errorCode === 'RECIPIENT_INVALID'
                        ? Suppression::INVALID
                        : Suppression::HARD_BOUNCE,
                    source: $provider->name(),
                    notification: $notification,
                );
            }

            // Un rejet métier n'est pas rattrapable par un autre fournisseur.
            if (! $lastResult->retryable) {
                break;
            }
        }

        $this->markFailed($notification, $lastResult);
    }

    private function recordAttempt(
        Notification $notification,
        string $provider,
        int $attempt,
        ProviderResult $result,
    ): void {
        NotificationDelivery::create([
            'notification_id' => $notification->id,
            'provider' => $provider,
            'attempt' => $attempt,
            'status' => match (true) {
                $result->accepted => NotificationDelivery::ACCEPTED,
                $result->retryable => NotificationDelivery::FAILED,
                default => NotificationDelivery::REJECTED,
            },
            'provider_message_id' => $result->messageId,
            'error_code' => $result->errorCode,
            'error_message' => $result->errorMessage,
            'cost_amount' => $result->costAmount,
            'cost_currency' => $result->costCurrency,
            'sent_at' => $result->accepted ? now() : null,
        ]);
    }

    private function markSent(Notification $notification): void
    {
        // `sent` signifie « accepté par le fournisseur », pas « reçu ».
        // `delivered` viendra du webhook, s'il vient.
        $notification->forceFill(['status' => Notification::SENT])->save();

        event(new DomainEvent('notify.message.sent', [
            'notification_id' => $notification->id,
            'channel' => $notification->channel,
            'template_key' => $notification->template_key,
        ], $notification->organization_id));
    }

    private function markFailed(Notification $notification, ?ProviderResult $result): void
    {
        $notification->forceFill([
            'status' => Notification::FAILED,
            'failed_reason' => $result?->errorCode ?? 'PROVIDER_ERROR',
        ])->save();

        // Un échec silencieux serait invisible : l'événement est le seul moyen
        // pour Analytics et les alertes d'en avoir connaissance.
        event(new DomainEvent('notify.message.failed', [
            'notification_id' => $notification->id,
            'channel' => $notification->channel,
            'template_key' => $notification->template_key,
            'reason' => $result?->errorCode ?? 'PROVIDER_ERROR',
        ], $notification->organization_id));
    }
}
