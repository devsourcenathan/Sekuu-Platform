<?php

declare(strict_types=1);

namespace Modules\Notify\Application\Events;

use App\Platform\Events\DomainEvent;
use App\Platform\Exceptions\DomainException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Modules\Notify\Application\Sending\SendNotification;
use Modules\Notify\Application\Sending\SendRequest;
use Modules\Notify\Domain\Channel;

/**
 * Traduit les événements de la plateforme en envois.
 *
 * C'est ici, et nulle part ailleurs, que se trouve la correspondance entre un
 * fait métier et un message. Ajouter un message ne modifie donc jamais le
 * module qui a publié l'événement.
 *
 * @see docs/03-services/notify/04-events.md
 */
final class DomainEventSubscriber implements ShouldQueue
{
    public string $queue = 'notifications';

    /**
     * Événement → clé de template.
     *
     * @var array<string, string>
     */
    private const ROUTES = [
        'identity.user.registered' => 'user.welcome',
        'identity.email.verification_requested' => 'email.verification',
        'identity.password.reset_requested' => 'password.reset',
        'identity.password.changed' => 'password.changed',
        'identity.invitation.sent' => 'invitation.sent',
        'identity.organization.created' => 'organization.created',
        'identity.session.new_device' => 'security.new_device',
        'identity.membership.removed' => 'membership.removed',

        // Billing ne peut pas prélever : la seule chose que la plateforme
        // puisse faire pour être payée est de prévenir. Ces correspondances
        // sont donc le pilier de l'ADR-0007, pas un confort.
        'billing.subscription.activated' => 'subscription.activated',
        'billing.subscription.renewed' => 'subscription.activated',
        'billing.subscription.expiring' => 'subscription.expiring',
        'billing.subscription.grace_started' => 'subscription.grace',
        'billing.subscription.suspended' => 'subscription.suspended',
        'billing.invoice.issued' => 'invoice.issued',
        'billing.invoice.paid' => 'invoice.paid',
        // Publié par **Billing**, pas par Payments : le préfixe désigne le
        // module émetteur, et c'est le propriétaire de la facture qui prévient
        // son client. Payments publie de son côté des événements nus, sans
        // destinataire, que Notify n'écoute pas.
        'billing.payment.failed' => 'payment.failed',
    ];

    public function __construct(private readonly SendNotification $send) {}

    public function handle(DomainEvent $event): void
    {
        $templateKey = self::ROUTES[$event->type] ?? null;

        if ($templateKey === null) {
            return;
        }

        // L'émetteur fournit les coordonnées dont il dispose ; ce sont les
        // templates qui déterminent lesquelles seront utilisées.
        $recipients = array_filter([
            Channel::EMAIL => (string) $event->get('recipient', ''),
            Channel::SMS => (string) $event->get('phone', ''),
            // Le canal interne s'adresse à un compte, pas à une coordonnée :
            // il n'est possible que si l'événement identifie l'utilisateur.
            Channel::IN_APP => (string) $event->get('user_id', ''),
        ]);

        if ($recipients === []) {
            Log::warning('Événement sans destinataire, ignoré.', [
                'type' => $event->type,
                'event_id' => $event->eventId,
            ]);

            return;
        }

        try {
            $this->send->handle(new SendRequest(
                templateKey: $templateKey,
                recipients: $recipients,
                variables: (array) $event->get('variables', []),
                userId: $event->get('user_id'),
                organizationId: $event->organizationId,
                locale: $event->get('locale'),
                // L'identifiant de l'événement fait office de clé d'idempotence :
                // la livraison étant « au moins une fois », un rejeu ne doit pas
                // produire un second message.
                idempotencyKey: $event->eventId,
                sourceEventId: $event->eventId,
            ));
        } catch (DomainException $e) {
            // Destinataire supprimé, désabonné, template absent : le message ne
            // partira pas, et réessayer n'y changerait rien. La notification a
            // déjà été enregistrée avec son motif.
            Log::info('Message non envoyé.', [
                'type' => $event->type,
                'template' => $templateKey,
                'reason' => $e->errorCode,
                'request_id' => $event->requestId,
            ]);
        }
    }
}
