<?php

declare(strict_types=1);

namespace Modules\Billing\Infrastructure\Providers;

use Modules\Billing\Domain\Models\PaymentAttempt;

/**
 * Frontière avec les agrégateurs de paiement.
 *
 * Tout adaptateur doit répondre à trois questions avant d'être mis en
 * production, et c'est le premier sujet de ses tests :
 *
 *  1. Quels statuts signifient « l'invite n'est jamais partie » ? Liste fermée.
 *     Tout le reste vaut `prompted`.
 *  2. Quelles issues d'appel autorisent une bascule ? Par défaut aucune :
 *     seules les erreurs d'authentification et de validation, jamais les
 *     temporisations.
 *  3. Comment retrouver une transaction à partir de notre `merchantReference` ?
 *     Sans cette capacité, un appel expiré reste à jamais irrésolu.
 *
 * @see docs/03-services/billing/05-providers.md
 */
interface PaymentProvider
{
    public function name(): string;

    /**
     * Un agrégateur sans identifiants n'est pas essayé du tout.
     *
     * C'est ce qui permet de développer sans compte marchand : aucun paiement
     * ne part, et le module le dit franchement plutôt que d'échouer à
     * l'exécution.
     */
    public function isConfigured(): bool;

    /** Le réseau du payeur est un fait ; tous les agrégateurs ne le couvrent pas. */
    public function supports(string $operator): bool;

    public function charge(ChargeRequest $request): ChargeOutcome;

    /**
     * Interrogation du statut réel.
     *
     * Obligatoire, jamais optionnelle : un callback se perd, et s'il est la
     * seule source d'information, un client peut avoir été débité sans que la
     * plateforme le sache.
     */
    public function poll(PaymentAttempt $attempt): ChargeOutcome;
}
