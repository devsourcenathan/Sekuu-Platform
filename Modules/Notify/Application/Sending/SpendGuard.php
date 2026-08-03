<?php

declare(strict_types=1);

namespace Modules\Notify\Application\Sending;

use App\Platform\Exceptions\DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Plafond de dépense mensuelle par organisation et par canal.
 *
 * Le coût unitaire du SMS rend ce garde-fou nécessaire : sans lui, une boucle
 * dans un produit ou une clé d'API fuitée se traduit par une facture, et rien
 * ne s'y oppose avant le relevé.
 *
 * Ce n'est **pas** une facturation — celle-ci appartient à Billing. C'est une
 * protection contre l'emballement.
 *
 * @see docs/03-services/notify/02-data-model.md
 */
final class SpendGuard
{
    public function assertWithinBudget(string $channel, ?string $organizationId): void
    {
        $limit = $this->limitFor($channel);

        if ($limit === null || $organizationId === null) {
            return;
        }

        if ($this->spentThisMonth($channel, $organizationId) >= $limit) {
            throw new DomainException(
                'QUOTA_EXCEEDED',
                __('notify::messages.spend_limit_reached', ['channel' => $channel]),
                429,
            );
        }
    }

    /**
     * Dépense constatée depuis le début du mois.
     *
     * La mesure est **rétrospective** : le coût n'est connu qu'une fois le
     * message accepté par le fournisseur. Un plafond peut donc être dépassé
     * d'un message — c'est le prix d'un contrôle qui ne ralentit pas l'envoi.
     */
    public function spentThisMonth(string $channel, string $organizationId): float
    {
        return (float) DB::table('notification_deliveries')
            ->join('notifications', 'notifications.id', '=', 'notification_deliveries.notification_id')
            ->where('notifications.organization_id', $organizationId)
            ->where('notifications.channel', $channel)
            ->where('notification_deliveries.created_at', '>=', now()->startOfMonth())
            // Additionner des devises différentes n'aurait aucun sens : seule
            // celle du plafond est comptée.
            ->where('notification_deliveries.cost_currency', $this->currency())
            ->sum('notification_deliveries.cost_amount');
    }

    public function limitFor(string $channel): ?float
    {
        $limit = config('notify.limits.'.$channel.'.monthly_cost');

        return $limit === null || $limit === '' ? null : (float) $limit;
    }

    public function currency(): string
    {
        return (string) config('notify.limits.currency');
    }
}
