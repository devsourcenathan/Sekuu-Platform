<?php

declare(strict_types=1);

namespace App\Platform\Contracts;

/**
 * Lecture synchrone de Billing par les autres modules.
 *
 * **Billing publie les limites ; il ne les fait pas respecter.** Chaque module
 * contrôle son propre quota, parce que lui seul sait le compter — Notify sait
 * combien de SMS il a envoyés, Billing ne le saura jamais mieux que lui.
 *
 * Ce contrat ne donne donc qu'une chose : la limite. Le comptage et le refus
 * appartiennent à l'appelant.
 *
 * @see docs/03-services/billing/01-overview.md
 */
interface BillingContract
{
    /**
     * Limite du plan courant d'une organisation pour une ressource.
     *
     * Clés en usage : `members`, `workspaces`, `storage_gb`, `sms_monthly`,
     * `ai_credits_monthly`.
     *
     * Une organisation sans abonnement vivant renvoie « non couvert ».
     */
    public function limit(string $organizationId, string $key): PlanLimit;
}
