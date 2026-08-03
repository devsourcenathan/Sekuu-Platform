<?php

declare(strict_types=1);

namespace Modules\Notify\Application\Delivery;

use App\Platform\Events\DomainEvent;
use Modules\Notify\Domain\Models\Notification;
use Modules\Notify\Domain\Models\Suppression;

/**
 * Inscrit une destination sur la liste de suppression.
 *
 * Deux chemins y mènent : un retour de webhook, ou un rejet du fournisseur au
 * moment de l'envoi. Les deux doivent produire exactement le même effet.
 *
 * @see docs/04-decisions/adr-0006-transactional-vs-marketing.md
 */
final class SuppressDestination
{
    public function handle(
        string $channel,
        string $destination,
        string $reason,
        ?string $source = null,
        ?Notification $notification = null,
    ): ?Suppression {
        $normalised = Suppression::normalise($destination);

        $existing = Suppression::query()
            ->where('channel', $channel)
            ->where('destination', $normalised)
            ->whereNull('expires_at')
            ->exists();

        if ($existing) {
            return null;
        }

        $suppression = Suppression::create([
            'channel' => $channel,
            'destination' => $normalised,
            'reason' => $reason,
            'source' => $source,
            'notification_id' => $notification?->id,
        ]);

        // Une adresse en rebond dur n'est plus un moyen de récupération de
        // compte : Identity doit pouvoir en tenir compte.
        event(new DomainEvent('notify.recipient.suppressed', [
            'channel' => $channel,
            'destination' => $normalised,
            'reason' => $reason,
        ], $notification?->organization_id));

        return $suppression;
    }
}
