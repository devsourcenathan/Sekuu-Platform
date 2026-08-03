<?php

declare(strict_types=1);

namespace Modules\Notify\Application\Sending;

use Modules\Notify\Domain\Category;
use Modules\Notify\Domain\Channel;
use Modules\Notify\Domain\Models\NotificationPreference;
use Modules\Notify\Domain\Models\Suppression;

/**
 * Décide si un message peut atteindre un destinataire.
 *
 * Deux mécanismes distincts, souvent confondus :
 * la **préférence** (le destinataire n'en veut pas) et la **suppression**
 * (la destination ne fonctionne plus).
 *
 * @see docs/04-decisions/adr-0006-transactional-vs-marketing.md
 */
final class RecipientFilter
{
    public function check(
        string $channel,
        string $category,
        string $destination,
        ?string $userId = null,
        ?string $organizationId = null,
    ): FilterVerdict {
        // La suppression prime sur tout, y compris sur les messages
        // transactionnels : une adresse en rebond dur n'est plus une adresse,
        // et continuer à écrire dégrade la réputation de tout le domaine.
        if ($this->isSuppressed($channel, $destination)) {
            return FilterVerdict::blocked(
                'RECIPIENT_SUPPRESSED',
                __('notify::messages.recipient_suppressed'),
            );
        }

        // Un message transactionnel ne peut pas être refusé par une préférence :
        // couper un lien de réinitialisation enfermerait l'utilisateur dehors.
        if (! Category::isOptional($category)) {
            return FilterVerdict::allowed();
        }

        if ($userId !== null && ! $this->isEnabled($userId, $organizationId, $category, $channel)) {
            return FilterVerdict::blocked(
                'RECIPIENT_OPTED_OUT',
                __('notify::messages.recipient_opted_out'),
            );
        }

        return FilterVerdict::allowed();
    }

    public function isSuppressed(string $channel, string $destination): bool
    {
        return Suppression::query()
            ->active()
            ->where('channel', $channel)
            ->where('destination', Suppression::normalise($destination))
            ->exists();
    }

    /**
     * Résolution : préférence pour l'organisation → préférence globale →
     * défaut de la catégorie.
     */
    private function isEnabled(string $userId, ?string $organizationId, string $category, string $channel): bool
    {
        $preferences = NotificationPreference::query()
            ->where('user_id', $userId)
            ->where('category', $category)
            ->where('channel', $channel)
            ->get();

        if ($organizationId !== null) {
            $scoped = $preferences->firstWhere('organization_id', $organizationId);

            if ($scoped !== null) {
                return $scoped->enabled;
            }
        }

        $global = $preferences->firstWhere('organization_id', null);

        if ($global !== null) {
            return $global->enabled;
        }

        return Category::enabledByDefault($category);
    }

    /**
     * Le canal interne ne dépend d'aucun fournisseur : il reste disponible
     * quand tout le reste échoue.
     */
    public function isAlwaysAvailable(string $channel): bool
    {
        return $channel === Channel::IN_APP;
    }
}
