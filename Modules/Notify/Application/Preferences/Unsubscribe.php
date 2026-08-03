<?php

declare(strict_types=1);

namespace Modules\Notify\Application\Preferences;

use App\Platform\Exceptions\DomainException;
use Modules\Notify\Application\Delivery\SuppressDestination;
use Modules\Notify\Domain\Category;
use Modules\Notify\Domain\Models\NotificationPreference;
use Modules\Notify\Domain\Models\Suppression;

/**
 * Applique un désabonnement demandé depuis un lien.
 *
 * @see docs/04-decisions/adr-0006-transactional-vs-marketing.md
 */
final class Unsubscribe
{
    public function __construct(private readonly SuppressDestination $suppressor) {}

    /**
     * @param  array{channel: string, destination: string, category: string, user_id: ?string}  $token
     */
    public function handle(array $token): string
    {
        // Un lien de désabonnement ne peut pas couper un message
        // transactionnel : l'utilisateur ne recevrait plus son propre lien de
        // réinitialisation.
        if (! Category::isOptional($token['category'])) {
            throw DomainException::unprocessable(
                'TRANSACTIONAL_CANNOT_BE_DISABLED',
                __('notify::messages.transactional_locked'),
            );
        }

        // Destinataire connu : on désactive la catégorie, sans toucher au
        // reste. C'est le cas normal, et le seul réversible par l'utilisateur.
        if ($token['user_id'] !== null) {
            NotificationPreference::query()->updateOrCreate(
                [
                    'user_id' => $token['user_id'],
                    'organization_id' => null,
                    'category' => $token['category'],
                    'channel' => $token['channel'],
                ],
                ['enabled' => false],
            );

            return 'preference';
        }

        // Destinataire inconnu de la plateforme — un invité, par exemple. Sans
        // compte auquel rattacher une préférence, le seul outil disponible est
        // la liste de suppression, qui bloque **toute** la destination.
        $this->suppressor->handle(
            channel: $token['channel'],
            destination: $token['destination'],
            reason: Suppression::UNSUBSCRIBE,
            source: 'unsubscribe-link',
        );

        return 'suppression';
    }
}
