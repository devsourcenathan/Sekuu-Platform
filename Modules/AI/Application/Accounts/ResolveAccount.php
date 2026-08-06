<?php

declare(strict_types=1);

namespace Modules\AI\Application\Accounts;

use App\Platform\Contracts\AiActor;
use App\Platform\Exceptions\DomainException;
use Modules\AI\Domain\Models\AiAccount;
use Modules\AI\Domain\Models\AiPlacement;

/**
 * Quel compte exécute.
 *
 * La tâche a nommé le modèle ; reste à savoir sur quelle clé il part, et donc
 * qui paie. Du plus précis au plus général, premier trouvé :
 *
 *  1. le compte nommé par l'appelant — « utilise ma clé » ;
 *  2. une règle de placement `(organisation, tâche)` ;
 *  3. une règle de placement `(organisation, *)` ;
 *  4. les comptes de la plateforme, par ordre de priorité.
 *
 * ## Ce que la résolution ne fait jamais : redescendre d'un rang
 *
 * Un compte nommé mais inéligible — non éprouvé, en pause, hors environnement —
 * **échoue**. Il ne se rabat pas sur le rang suivant.
 *
 * C'est la règle de Storage, et pour une raison plus forte ici : se rabattre sur
 * un compte de la plateforme ferait payer **nous** à la place du client, sans
 * que personne l'ait décidé, et la facture n'arriverait qu'un mois plus tard.
 *
 * @see docs/03-services/ai/05-providers.md
 */
final class ResolveAccount
{
    /**
     * Les comptes éligibles, dans l'ordre d'essai.
     *
     * Rendre une **liste** et non un compte est ce qui permet à la bascule du
     * §4 d'exister : un `429` passe au suivant. Les rangs 1 à 3 n'en rendent
     * jamais qu'un — une désignation explicite n'a pas de suite.
     *
     * Le modèle n'entre pas ici. C'est `RunTask` qui apparie modèles et comptes,
     * parce que lui seul connaît la chaîne de la tâche et l'ordre dans lequel il
     * veut la parcourir.
     *
     * @return list<AiAccount>
     */
    public function handle(string $task, AiActor $actor, ?string $requested = null): array
    {
        if ($requested !== null) {
            return [$this->named($requested, $actor)];
        }

        if ($actor->organizationId !== null) {
            $place = $this->fromPlacements($actor->organizationId, $task, $actor);

            if ($place !== null) {
                return [$place];
            }
        }

        return $this->platformAccounts();
    }

    /**
     * Un compte désigné nommément.
     *
     * Trois refus distincts, et la distinction compte : « il n'existe pas »,
     * « il n'est pas à vous » et « il n'a pas réussi l'épreuve » appellent trois
     * gestes différents de la part de celui qui reçoit l'erreur.
     */
    private function named(string $slug, AiActor $actor): AiAccount
    {
        $account = AiAccount::query()->where('slug', $slug)->first();

        if ($account === null) {
            throw DomainException::notFound('AI_ACCOUNT_NOT_FOUND', __('ai::messages.account_not_found'));
        }

        $this->guardOwnership($account, $actor);

        if (! $account->canGenerate() || $account->environment !== app()->environment()) {
            throw DomainException::conflict(
                'AI_ACCOUNT_UNVERIFIED',
                __('ai::messages.account_unverified', ['slug' => $slug]),
            );
        }

        return $account;
    }

    /**
     * Un compte de la plateforme sert tout le monde ; celui d'un tiers ne sert
     * que lui.
     *
     * Sans ce contrôle, connaître le nom d'un compte suffirait à s'en servir —
     * et à faire porter la dépense d'autrui. Une clé d'IA fuitée se dépense, et
     * elle se dépense vite.
     */
    private function guardOwnership(AiAccount $account, AiActor $actor): void
    {
        if ($account->belongsToPlatform()) {
            return;
        }

        $sien = ($account->owner_api_key_id !== null && $account->owner_api_key_id === $actor->id)
            || ($account->owner_organization_id !== null && $account->owner_organization_id === $actor->organizationId);

        if (! $sien) {
            throw DomainException::forbidden('AI_ACCOUNT_FORBIDDEN', __('ai::messages.account_forbidden'));
        }
    }

    /**
     * Une règle de placement est une **déclaration**, pas une préférence : si
     * elle désigne un compte hors service, la génération échoue.
     *
     * Le contraire ferait basculer chez nous, en silence, un client qui a
     * demandé que tout passe par sa clé — c'est-à-dire qu'il l'a demandé pour
     * une raison, souvent contractuelle.
     */
    private function fromPlacements(string $organizationId, string $task, AiActor $actor): ?AiAccount
    {
        $regles = AiPlacement::query()
            ->with('account')
            ->where('organization_id', $organizationId)
            ->where(fn ($query) => $query->where('task', $task)->orWhereNull('task'))
            ->get()
            // Une règle nommant la tâche l'emporte sur l'attrape-tout : c'est la
            // plus précise, donc la plus délibérée.
            ->sortByDesc(fn (AiPlacement $p): int => $p->task === null ? 0 : 1);

        $regle = $regles->first();

        if ($regle === null || $regle->account === null) {
            return null;
        }

        $this->guardOwnership($regle->account, $actor);

        if (! $regle->account->canGenerate() || $regle->account->environment !== app()->environment()) {
            throw DomainException::conflict(
                'AI_ACCOUNT_UNVERIFIED',
                __('ai::messages.account_unverified', ['slug' => (string) $regle->account->slug]),
            );
        }

        return $regle->account;
    }

    /**
     * Les nôtres, par ordre de priorité déclaré.
     *
     * Ce n'est pas de la répartition de charge : ils sont essayés **dans
     * l'ordre**, et seul un refus du premier fait passer au second. Un
     * tourniquet optimiserait une file qui n'existe pas encore, et rendrait le
     * coût d'un appel dépendant du hasard.
     *
     * @return list<AiAccount>
     */
    private function platformAccounts(): array
    {
        return AiAccount::query()
            ->whereNull('owner_organization_id')
            ->whereNull('owner_api_key_id')
            ->where('environment', app()->environment())
            ->where('status', AiAccount::ACTIVE)
            ->orderBy('priority')
            ->orderBy('created_at')
            ->get()
            ->all();
    }
}
