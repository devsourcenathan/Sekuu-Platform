<?php

declare(strict_types=1);

namespace Modules\Notify\Application\Delivery;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Notify\Domain\Models\Notification;
use Modules\Notify\Domain\Models\NotificationDelivery;
use Modules\Notify\Domain\Models\NotificationEvent;
use Modules\Notify\Domain\Models\Suppression;
use Modules\Notify\Infrastructure\Webhooks\NormalisedDeliveryEvent;

/**
 * Applique un retour de fournisseur.
 *
 *   1. Déduplication      les fournisseurs rejouent leurs webhooks
 *   2. Rapprochement      via provider_message_id
 *   3. Enregistrement     événement append-only, statut mis à jour
 *   4. Suppression        rebond dur ou plainte → destination supprimée
 *
 * @see docs/03-services/notify/03-api.md
 */
final class RecordDeliveryEvent
{
    public function __construct(private readonly SuppressDestination $suppressor) {}

    public function handle(string $provider, NormalisedDeliveryEvent $event): ?NotificationEvent
    {
        $delivery = $event->providerMessageId === null
            ? null
            : NotificationDelivery::query()
                ->where('provider_message_id', $event->providerMessageId)
                ->latest('attempt')
                ->first();

        $notification = $delivery?->notification()->first();

        // Un retour non rapproché reste utile pour la liste de suppression :
        // une adresse qui rebondit doit être supprimée même si le message
        // d'origine a été purgé.
        if ($notification === null) {
            $this->suppressIfNeeded($provider, $event, null);

            Log::info('Retour fournisseur non rapproché.', [
                'provider' => $provider,
                'provider_message_id' => $event->providerMessageId,
                'type' => $event->type,
            ]);

            return null;
        }

        $record = $this->record($provider, $event, $notification, $delivery);

        if ($record === null) {
            return null;
        }

        $this->updateStatus($notification, $event);
        $this->suppressIfNeeded($provider, $event, $notification);

        return $record;
    }

    private function record(
        string $provider,
        NormalisedDeliveryEvent $event,
        Notification $notification,
        ?NotificationDelivery $delivery,
    ): ?NotificationEvent {
        try {
            // Transaction imbriquée, donc SAVEPOINT : sur PostgreSQL, une
            // erreur annule toute la transaction courante. Sans point de
            // sauvegarde, rattraper la violation d'unicité laisserait la
            // transaction inutilisable pour la suite.
            return DB::transaction(fn () => NotificationEvent::create([
                'notification_id' => $notification->id,
                'delivery_id' => $delivery?->id,
                'type' => $event->type,
                'provider' => $provider,
                'provider_event_id' => $event->providerEventId,
                'payload' => $event->payload,
                'occurred_at' => $event->occurredAt ?? now(),
            ]));
        } catch (QueryException $e) {
            // L'index unique (provider, provider_event_id) tranche : le
            // rejeu est absorbé par le schéma, pas par une vérification
            // applicative qu'on pourrait oublier.
            if (self::isUniqueViolation($e)) {
                return null;
            }

            throw $e;
        }
    }

    private function updateStatus(Notification $notification, NormalisedDeliveryEvent $event): void
    {
        $status = match ($event->type) {
            'delivered' => Notification::DELIVERED,
            'bounced', 'rejected' => Notification::FAILED,
            default => null,
        };

        if ($status === null || $notification->status === $status) {
            return;
        }

        // `sent` signifiait « accepté par le fournisseur » ; c'est seulement
        // maintenant qu'on sait si le message est arrivé.
        $notification->forceFill([
            'status' => $status,
            'failed_reason' => $status === Notification::FAILED ? mb_strtoupper($event->type) : null,
        ])->save();
    }

    /**
     * Un rebond temporaire — boîte pleine, serveur indisponible — ne supprime
     * pas : il a déjà déclenché un réessai.
     */
    private function suppressIfNeeded(
        string $provider,
        NormalisedDeliveryEvent $event,
        ?Notification $notification,
    ): void {
        if (! in_array($event->type, ['bounced', 'complained', 'unsubscribed'], true)) {
            return;
        }

        if ($event->type === 'bounced' && ! $event->permanentFailure) {
            return;
        }

        $destination = $event->destination ?? $notification?->recipient;
        $channel = $notification?->channel ?? ($provider === 'postmark' ? 'email' : 'sms');

        if ($destination === null) {
            return;
        }

        $reason = match ($event->type) {
            'complained' => Suppression::COMPLAINT,
            'unsubscribed' => Suppression::UNSUBSCRIBE,
            default => Suppression::HARD_BOUNCE,
        };

        $this->suppressor->handle($channel, $destination, $reason, $provider, $notification);
    }

    private static function isUniqueViolation(QueryException $e): bool
    {
        return $e->getCode() === '23505'
            || str_contains(strtolower($e->getMessage()), 'unique');
    }
}
