<?php

declare(strict_types=1);

namespace Modules\AI\Application\Generation;

use App\Platform\Contracts\BillingContract;
use App\Platform\Exceptions\DomainException;
use Illuminate\Support\Facades\DB;
use Modules\AI\Domain\Models\AiAccount;

/**
 * Ce que l'IA coûte : les deux bornes qui l'encadrent, et le registre qui le
 * constate.
 *
 * ## Pourquoi deux bornes
 *
 * | | Quota du plan | Plafond absolu |
 * | --- | --- | --- |
 * | Source | Billing, `ai_credits_monthly` | Configuration de la plateforme |
 * | Rôle | limite **commerciale** | garde-fou contre l'emballement |
 * | Porte sur | une organisation | la plateforme entière |
 *
 * Supprimer le second laisserait une organisation au plan illimité sans aucune
 * borne — et une clé fuitée sur un plan « illimité » est précisément le scénario
 * où l'on perd de l'argent. C'est la coexistence déjà en place entre
 * `ChannelQuota` et `SpendGuard` côté Notify.
 *
 * ## L'unité est le millionième de dollar
 *
 * C'est la monnaie dans laquelle les fournisseurs facturent. Convertir en francs
 * à l'écriture figerait un taux de change dans un registre scellé, et le total
 * d'un mois mêlerait autant de taux que de jours.
 *
 * @see docs/04-decisions/adr-0016-ai-spend-and-privacy.md
 */
final class SpendLedger
{
    public const QUOTA_KEY = 'ai_credits_monthly';

    /** 80 % puis 100 % : prévenir, puis constater. */
    private const THRESHOLDS = [0.8, 1.0];

    public function __construct(
        private readonly BillingContract $billing,
        private readonly AnnounceOutcome $announce,
    ) {}

    /**
     * Les trois bornes, avant l'appel.
     *
     * ## Vérifiées sur le constaté, pas sur une projection
     *
     * On refuse quand la dépense **déjà faite** franchit la limite, pas quand le
     * coût maximal théorique du prochain appel la franchirait. La seconde
     * approche bloquerait bien avant que la limite soit atteinte : le coût
     * maximal d'une tâche à 16 000 jetons de sortie est très au-dessus de ce
     * qu'elle consomme réellement.
     *
     * Le prix est un dépassement possible de la valeur d'un appel, assumé et
     * documenté. Le plafond absolu, lui, ne dépend d'aucune estimation.
     */
    public function assertMayRun(AiAccount $account, ?string $organizationId): void
    {
        $this->assertPlatformCapNotReached();
        $this->assertAccountCapNotReached($account);

        /*
         * Le quota du plan ne porte **que** sur nos comptes.
         *
         * Ce qu'un client dépense sur sa propre clé, il le paie à son
         * fournisseur ; le lui compter reviendrait à lui facturer deux fois la
         * même chose — une fois en crédits, une fois en dollars.
         */
        if (! $account->belongsToPlatform() || $organizationId === null) {
            return;
        }

        $limit = $this->billing->limit($organizationId, self::QUOTA_KEY);

        /*
         * Non couvert ou illimité : rien à plafonner.
         *
         * « Non couvert » n'est **pas** un refus. Un quota borne un usage
         * autorisé, il ne décide pas de l'autorisation — celle-ci vient de
         * l'activation du produit côté Identity. Refuser ici fermerait toute
         * organisation créée avant qu'un abonnement n'existe.
         */
        if (! $limit->covered || $limit->isUnlimited()) {
            return;
        }

        $spent = $this->spentThisMonth($organizationId);

        if ($limit->allows($spent)) {
            return;
        }

        throw new DomainException('AI_QUOTA_EXCEEDED', __('ai::messages.quota_exceeded'), 429, [
            'limit' => self::QUOTA_KEY,
            'credits' => $limit->value,
            'used' => $spent,
        ]);
    }

    /**
     * Le garde-fou de la plateforme, indépendant de tout plan.
     */
    private function assertPlatformCapNotReached(): void
    {
        $cap = (int) config('ai.spend_cap_micros', 0);

        if ($cap <= 0) {
            return;
        }

        $spent = (int) DB::table('ai_spend')->where('period', $this->period())->sum('cost_micros');

        if ($spent >= $cap) {
            throw new DomainException('AI_SPEND_CAP_REACHED', __('ai::messages.spend_cap_reached'), 429);
        }
    }

    /**
     * Le plafond propre au compte.
     *
     * Sur le compte d'un client, c'est **sa seule borne** : notre plafond
     * protège notre argent, pas le sien, et son plan ne compte pas ce qu'il
     * dépense chez lui. Sans ce champ, une boucle dans son produit brûlerait sa
     * clé sans que rien de notre côté ne s'y oppose.
     */
    private function assertAccountCapNotReached(AiAccount $account): void
    {
        $cap = $account->spend_cap_micros;

        if ($cap === null || $cap <= 0) {
            return;
        }

        $spent = (int) DB::table('ai_generations')
            ->where('account_id', $account->id)
            ->where('created_at', '>=', now()->startOfMonth())
            ->sum('cost_micros');

        if ($spent >= $cap) {
            throw new DomainException('AI_ACCOUNT_CAP_REACHED', __('ai::messages.account_cap_reached'), 429);
        }
    }

    /**
     * Constater ce qui a été dépensé.
     *
     * ## Deux colonnes, jamais une somme
     *
     * Le quota ne porte que sur nos comptes, et les deux nombres n'ont même pas
     * la même exactitude : le nôtre suit une facture, celui d'un client suit des
     * prix publics que son tarif négocié contredit peut-être. Un total les
     * mêlant ne voudrait rien dire, et servirait quand même de base à une
     * décision.
     *
     * ## Appelé même en échec
     *
     * Un modèle qui a produit une réponse hors schéma a consommé des jetons.
     * Ne pas les compter reviendrait à s'offrir les échecs — et à découvrir
     * l'écart sur la facture du fournisseur.
     */
    public function record(?string $organizationId, AiAccount $account, ?int $costMicros): void
    {
        if ($organizationId === null) {
            return;
        }

        $column = $account->belongsToPlatform() ? 'cost_micros' : 'cost_micros_byo';
        $period = $this->period();
        $before = $this->spentThisMonth($organizationId);

        /*
         * Deux temps, et pas un `upsert` porteur de valeur.
         *
         * Un `upsert` qui écrit le montant puis l'incrémente compte deux fois à
         * la première écriture du mois. C'est le défaut trouvé dans
         * `StorageQuota::adjust`, et il ne se voyait que le premier jour du mois.
         */
        DB::table('ai_spend')->insertOrIgnore([
            'organization_id' => $organizationId,
            'period' => $period,
            'cost_micros' => 0,
            'cost_micros_byo' => 0,
            'generations' => 0,
        ]);

        /*
         * Un coût inconnu ajoute zéro, et incrémente quand même le compteur de
         * générations. C'est volontaire : l'écart entre les deux colonnes est
         * précisément ce qui signale qu'un modèle sans prix public a servi.
         * L'agrégat sous-estime alors, et il le dit.
         */
        DB::table('ai_spend')
            ->where('organization_id', $organizationId)
            ->where('period', $period)
            ->update([
                $column => DB::raw($column.' + '.(int) $costMicros),
                'generations' => DB::raw('generations + 1'),
                'updated_at' => now(),
            ]);

        $this->announceThreshold($organizationId, $account, $before);
    }

    /**
     * Annoncer un seuil **au moment où il est franchi**, et une seule fois.
     *
     * ## Pourquoi comparer un avant et un après
     *
     * Annoncer « au-delà de 80 % » produirait un message à chaque génération
     * jusqu'à la fin du mois. Le produit apprendrait à les ignorer, et ignorerait
     * aussi celui des 100 %.
     *
     * Le franchissement, lui, n'arrive qu'une fois par seuil et par mois : la
     * comparaison porte sur la dépense d'avant et celle d'après, pas sur un
     * état.
     *
     * ## Seulement sur nos comptes
     *
     * Ce qu'un client dépense sur sa propre clé ne consomme pas ses crédits.
     * Lui annoncer un seuil qu'il n'approche pas serait une fausse alerte, et
     * une fausse alerte coûte la crédibilité des vraies.
     */
    private function announceThreshold(string $organizationId, AiAccount $account, int $before): void
    {
        if (! $account->belongsToPlatform()) {
            return;
        }

        $limit = $this->billing->limit($organizationId, self::QUOTA_KEY);

        if (! $limit->covered || $limit->isUnlimited() || (int) $limit->value <= 0) {
            return;
        }

        $credits = (int) $limit->value;
        $after = $this->spentThisMonth($organizationId);

        foreach (self::THRESHOLDS as $threshold) {
            $mark = (int) ceil($credits * $threshold);

            if ($before < $mark && $after >= $mark) {
                $this->announce->handle($organizationId, AnnounceOutcome::THRESHOLD, [
                    'period' => $this->period(),
                    'threshold' => $threshold,
                    'credits' => $credits,
                    'used' => $after,
                    'remaining' => max(0, $credits - $after),
                ]);
            }
        }
    }

    /**
     * Ce que **nos** comptes ont coûté à cette organisation ce mois-ci.
     *
     * C'est le seul nombre opposable : celui des comptes du client ne lui est
     * pas compté.
     */
    public function spentThisMonth(string $organizationId): int
    {
        return (int) DB::table('ai_spend')
            ->where('organization_id', $organizationId)
            ->where('period', $this->period())
            ->value('cost_micros');
    }

    public function remaining(?string $organizationId): ?int
    {
        if ($organizationId === null) {
            return null;
        }

        $limit = $this->billing->limit($organizationId, self::QUOTA_KEY);

        if (! $limit->covered || $limit->isUnlimited()) {
            return null;
        }

        return max(0, (int) $limit->value - $this->spentThisMonth($organizationId));
    }

    public function period(): string
    {
        return now()->format('Y-m');
    }
}
