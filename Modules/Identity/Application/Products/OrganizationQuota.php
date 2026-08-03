<?php

declare(strict_types=1);

namespace Modules\Identity\Application\Products;

use App\Platform\Support\QuotaGuard;
use Illuminate\Support\Facades\DB;

/**
 * Quotas de sièges et de workspaces.
 *
 * Identity compte, Billing plafonne : chaque module contrôle son propre quota,
 * parce que lui seul sait le compter.
 *
 * @see docs/03-services/billing/01-overview.md
 */
final class OrganizationQuota
{
    public function __construct(private readonly QuotaGuard $quota) {}

    public function assertCanAddMember(?string $organizationId): void
    {
        if ($organizationId === null) {
            return;
        }

        $this->quota->assertAllows(
            $organizationId,
            'members',
            $this->seatsTaken($organizationId),
            __('identity::messages.member_quota_reached'),
        );
    }

    public function assertCanCreateWorkspace(?string $organizationId): void
    {
        if ($organizationId === null) {
            return;
        }

        $this->quota->assertAllows(
            $organizationId,
            'workspaces',
            $this->workspaces($organizationId),
            __('identity::messages.workspace_quota_reached'),
        );
    }

    /**
     * Sièges occupés : membres actifs **plus** invitations en attente.
     *
     * Ne compter que les membres laisserait envoyer cent invitations sur un
     * plan de trois sièges — le quota ne serait constaté qu'à l'acceptation,
     * c'est-à-dire trop tard, une fois la promesse faite à l'invité.
     */
    public function seatsTaken(string $organizationId): int
    {
        $members = DB::table('memberships')
            ->where('organization_id', $organizationId)
            ->where('status', 'active')
            ->count();

        // Une invitation est en attente quand elle n'est ni acceptée, ni
        // révoquée, ni expirée : la table ne porte pas de statut, l'état se
        // déduit des dates.
        $pending = DB::table('invitations')
            ->where('organization_id', $organizationId)
            ->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->count();

        return $members + $pending;
    }

    public function workspaces(string $organizationId): int
    {
        return DB::table('workspaces')
            ->where('organization_id', $organizationId)
            ->whereNull('deleted_at')
            ->count();
    }
}
