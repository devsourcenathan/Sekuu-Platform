<?php

declare(strict_types=1);

namespace Modules\Notify\Infrastructure\Providers;

use Modules\Notify\Domain\Channel;
use Modules\Notify\Domain\Models\Notification;

/**
 * Canal interne.
 *
 * Il n'a pas de fournisseur externe : la notification **est** l'entrée de
 * boîte de réception. « Livrer » revient donc à constater qu'elle est
 * disponible.
 *
 * C'est ce qui en fait le repli universel : il reste opérationnel quand tous
 * les autres canaux échouent.
 */
final class InAppProvider implements MessageProvider
{
    public function name(): string
    {
        return 'in-app';
    }

    public function channel(): string
    {
        return Channel::IN_APP;
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function send(Notification $notification): ProviderResult
    {
        // Aucun appel réseau, donc aucune incertitude : le message est
        // immédiatement consultable par son destinataire.
        return ProviderResult::accepted(messageId: $notification->id);
    }
}
