<?php

declare(strict_types=1);

namespace Modules\AI\Application\Accounts;

use App\Platform\Events\DomainEvent;
use App\Platform\Exceptions\DomainException;
use Illuminate\Support\Facades\Event;
use Modules\AI\Domain\Models\AiAccount;
use Modules\AI\Infrastructure\Drivers\DriverRegistry;
use Throwable;

/**
 * L'épreuve : générer un jeton, pour de vrai.
 *
 * ## Pourquoi elle consomme
 *
 * Une épreuve qui listerait les modèles ne prouverait pas ce qui compte. Un
 * compte peut lister sans avoir de crédit, sans être autorisé sur le modèle
 * qu'on lui demandera, ou avec une clé restreinte à une autre organisation chez
 * le fournisseur.
 *
 * Le coût est de l'ordre du millionième d'unité, et il est **imputé à la
 * plateforme, jamais au propriétaire du compte** : nous ne facturons pas à un
 * client le fait de vérifier notre propre configuration. Concrètement, rien
 * n'est écrit dans `ai_spend` ici — seul `RunTask` y écrit.
 *
 * ## Pourquoi elle est quotidienne et non horaire
 *
 * Parce qu'elle coûte. Storage éprouve ses magasins aussi souvent qu'il veut :
 * écrire un objet témoin de trente octets est gratuit. Ici, la même cadence
 * multiplierait un petit montant par le nombre de comptes et le nombre d'heures.
 *
 * @see docs/03-services/ai/05-providers.md
 */
final class VerifyAccount
{
    public const CREDENTIALS_REJECTED = 'credentials_rejected';

    public const MODEL_UNAVAILABLE = 'model_unavailable';

    /**
     * Un compte parfaitement valide dont le crédit chez le fournisseur est
     * épuisé.
     *
     * Ni une erreur d'identifiants, ni une panne — et la confusion enverrait
     * régénérer une clé qui n'a rien de cassé. Contrairement à un débit trop
     * rapide, celui-ci sort le compte du service : il ne servira plus tant que
     * personne n'aura payé.
     */
    public const QUOTA_EXHAUSTED = 'quota_exhausted';

    /**
     * Le fournisseur a répondu, et il a dit « pas maintenant ».
     *
     * Ce n'est **pas** un échec du compte, et c'est toute la différence avec les
     * autres raisons : la clé est bonne, le modèle existe, le crédit est là. Un
     * compte retiré du service sur un `429` le serait précisément aux heures de
     * charge — c'est-à-dire quand on en a besoin.
     */
    public const RATE_LIMITED = 'rate_limited';

    public const UNREACHABLE = 'unreachable';

    /**
     * La faute vient de **nous**, pas du fournisseur.
     *
     * Cette catégorie existe parce que son absence a coûté un déploiement côté
     * Storage : un adaptateur Flysystem manquant avait été rangé dans
     * `unreachable`, et le diagnostic est parti chercher du côté du réseau et des
     * identifiants — partout sauf là où était le défaut.
     *
     * Un fournisseur injoignable se corrige dans un tableau de bord ; celui-ci se
     * corrige dans le dépôt.
     */
    public const INTERNAL_ERROR = 'internal_error';

    public function __construct(private readonly DriverRegistry $drivers) {}

    public function handle(AiAccount $account): bool
    {
        $wasServing = $account->status === AiAccount::ACTIVE;

        try {
            $this->drivers->for($account)->probe($account);
        } catch (Throwable $e) {
            return $this->fail($account, $this->classify($e), $e->getMessage(), $wasServing);
        }

        $account->forceFill([
            'status' => $account->status === AiAccount::DISABLED ? AiAccount::DISABLED : AiAccount::ACTIVE,
            'verified_at' => now(),
            'verification_reason' => null,
            'verification_error' => null,
        ])->save();

        return true;
    }

    /**
     * Un compte `paused` ou `disabled` reste dans son état : ce sont des
     * décisions humaines, et l'épreuve n'a pas à les défaire.
     *
     * Un compte limité par le débit reste dans le sien aussi, pour la raison
     * dite plus haut — mais la raison est conservée, parce qu'un compte
     * durablement saturé est une information d'exploitation.
     */
    private function fail(AiAccount $account, string $reason, string $error, bool $wasServing): bool
    {
        $state = match (true) {
            $reason === self::RATE_LIMITED => $account->status,
            in_array($account->status, [AiAccount::PAUSED, AiAccount::DISABLED], true) => $account->status,
            default => AiAccount::UNVERIFIED,
        };

        $account->forceFill([
            'status' => $state,
            'verification_reason' => $reason,

            /*
             * Le message brut est conservé en base pour un exploitant, jamais
             * publié : une erreur de fournisseur peut porter un identifiant
             * d'organisation, un nom de déploiement, une empreinte de clé.
             */
            'verification_error' => mb_substr($error, 0, 2000),
        ])->save();

        // Seul un compte qui **servait** et qui ne sert plus produit
        // l'événement. Un compte déjà hors service qui échoue à nouveau n'est
        // pas une nouvelle.
        if ($wasServing && $state !== AiAccount::ACTIVE) {
            Event::dispatch(new DomainEvent('ai.account.unverified', [
                'account_id' => (string) $account->id,
                'slug' => (string) $account->slug,
                'reason' => $reason,
                'since' => now()->toIso8601String(),
            ]));
        }

        return false;
    }

    /**
     * Les pilotes ont déjà distingué ce que le statut HTTP permet de distinguer
     * sans ambiguïté. On s'appuie dessus plutôt que de relire des messages :
     * une correspondance de chaîne sur le texte d'un fournisseur casse le jour
     * où il reformule.
     */
    private function classify(Throwable $e): string
    {
        if ($e instanceof \Error) {
            return self::INTERNAL_ERROR;
        }

        if ($e instanceof DomainException) {
            return match ($e->errorCode) {
                'AI_CREDENTIALS_REJECTED' => self::CREDENTIALS_REJECTED,
                'AI_MODEL_UNAVAILABLE' => self::MODEL_UNAVAILABLE,
                'AI_RATE_LIMITED' => self::RATE_LIMITED,
                'AI_CREDIT_EXHAUSTED' => self::QUOTA_EXHAUSTED,
                default => self::UNREACHABLE,
            };
        }

        return self::UNREACHABLE;
    }
}
