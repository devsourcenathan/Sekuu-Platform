<?php

declare(strict_types=1);

namespace Modules\Notify\Application\Sending;

use App\Platform\Support\QuotaGuard;
use Illuminate\Support\Facades\DB;
use Modules\Notify\Domain\Channel;

/**
 * Quota de volume par canal, issu du plan de l'organisation.
 *
 * À distinguer de [SpendGuard], avec lequel il coexiste :
 *
 * | | `ChannelQuota` | `SpendGuard` |
 * | --- | --- | --- |
 * | Mesure | un **volume** de messages | une **dépense** |
 * | Source | le plan de l'organisation | la configuration de la plateforme |
 * | Rôle | limite **commerciale** | garde-fou contre l'emballement |
 *
 * Le plafond global était jusqu'ici un substitut aux quotas par plan, faute de
 * Billing. Maintenant qu'ils existent, il redevient ce qu'il aurait dû être
 * d'emblée : un filet absolu contre une boucle ou une clé fuitée, indépendant
 * du plan. Les deux ne se remplacent pas — supprimer le second laisserait une
 * organisation au plan illimité sans aucune borne.
 *
 * @see docs/03-services/billing/01-overview.md
 */
final class ChannelQuota
{
    /**
     * Clé de quota par canal. Un canal absent n'est pas plafonné : le canal
     * interne ne coûte rien, et l'email est facturé au forfait.
     */
    private const KEYS = [
        Channel::SMS => 'sms_monthly',
        Channel::WHATSAPP => 'whatsapp_monthly',
    ];

    public function __construct(private readonly QuotaGuard $quota) {}

    public function assertWithinQuota(string $channel, ?string $organizationId): void
    {
        $key = self::KEYS[$channel] ?? null;

        if ($key === null || $organizationId === null) {
            return;
        }

        $this->quota->assertAllows(
            $organizationId,
            $key,
            $this->sentThisMonth($channel, $organizationId),
            __('notify::messages.channel_quota_reached', ['channel' => $channel]),
        );
    }

    /**
     * Messages **acceptés** ce mois-ci, et non livrés.
     *
     * Compter les livraisons laisserait passer tout ce qui est en file : un
     * envoi groupé franchirait le quota avant qu'aucun message n'ait abouti.
     */
    public function sentThisMonth(string $channel, string $organizationId): int
    {
        return DB::table('notifications')
            ->where('organization_id', $organizationId)
            ->where('channel', $channel)
            ->where('created_at', '>=', now()->startOfMonth())
            ->whereNotIn('status', ['cancelled', 'suppressed'])
            ->count();
    }

    public function remaining(string $channel, ?string $organizationId): ?int
    {
        $key = self::KEYS[$channel] ?? null;

        if ($key === null || $organizationId === null) {
            return null;
        }

        return $this->quota->remaining($organizationId, $key, $this->sentThisMonth($channel, $organizationId));
    }
}
