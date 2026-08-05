<?php

declare(strict_types=1);

namespace Modules\Billing\Presentation\Http\Controllers;

use App\Platform\Contracts\PlatformContext;
use App\Platform\Exceptions\DomainException;
use App\Platform\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Billing\Application\Plans\GrantLimits;
use Modules\Billing\Domain\Models\Plan;
use Modules\Identity\Application\Audit\AuditLogger;

/**
 * Le catalogue, vu et modifié par un opérateur de plateforme.
 *
 * C'est ce qui rend un quota modifiable **sans toucher au code** : `plans.limits`
 * était déjà une colonne `jsonb`, il manquait le moyen de l'écrire.
 *
 * @see docs/04-decisions/adr-0018-platform-operator.md
 * @see docs/04-decisions/adr-0019-granted-limits.md
 */
final class PlatformPlanController
{
    /**
     * Les clés de quota reconnues.
     *
     * Liste **close**, et c'est délibéré : une clé inventée serait acceptée en
     * base, lue par personne, et donnerait l'illusion d'une limite en place.
     * Un quota qui ne borne rien est pire que pas de quota — on cesse de
     * surveiller ce qu'on croit borné.
     *
     * @var list<string>
     */
    private const KEYS = [
        'members', 'workspaces', 'storage_gb', 'sms_monthly',
        'whatsapp_monthly', 'ai_credits_monthly',
    ];

    public function __construct(
        private readonly GrantLimits $grant,
        private readonly AuditLogger $audit,
        private readonly PlatformContext $platform,
    ) {}

    public function index(): JsonResponse
    {
        $plans = Plan::query()->with('prices')->orderBy('name')->get();

        return ApiResponse::success($plans->map(fn (Plan $p): array => $this->present($p))->all());
    }

    public function update(Request $request, string $key): JsonResponse
    {
        $validated = $request->validate([
            'limits' => ['required', 'array'],
            'limits.*' => ['nullable', 'integer', 'min:0'],
            'remove' => ['sometimes', 'array'],
            'remove.*' => ['string'],
        ]);

        $plan = Plan::query()->where('key', $key)->first();

        if ($plan === null) {
            throw DomainException::notFound('PLAN_NOT_FOUND', __('billing::messages.plan_not_found'));
        }

        $inconnues = array_diff(
            [...array_keys($validated['limits']), ...(array) ($request->input('remove') ?? [])],
            self::KEYS,
        );

        if ($inconnues !== []) {
            throw DomainException::unprocessable(
                'PLAN_LIMIT_UNKNOWN',
                __('billing::messages.plan_limit_unknown', ['keys' => implode(', ', $inconnues)]),
            );
        }

        $avant = (array) ($plan->limits ?? []);

        /*
         * `PATCH` **fusionne**, il ne remplace pas.
         *
         * Un remplacement ferait qu'envoyer une seule clé efface toutes les
         * autres — un opérateur qui corrige `ai_credits_monthly` viderait les
         * sièges, les workspaces et le stockage du plan, en une requête qui
         * répond `200`.
         *
         * Retirer une clé demande donc de la nommer : `remove`. C'est plus
         * verbeux, et c'est le point — fermer une ressource ne doit pas être
         * l'effet de bord d'autre chose.
         */
        $apres = [...$avant, ...$validated['limits']];
        $apres = array_diff_key($apres, array_flip((array) ($validated['remove'] ?? [])));

        $plan->forceFill(['limits' => $apres])->save();

        // Reporté aux abonnements : les hausses tout de suite, les baisses au
        // renouvellement.
        $effet = $this->grant->afterPlanChange($plan);

        /*
         * L'avant **et** l'après.
         *
         * Le garde de route journalise déjà l'appel ; ceci journalise la
         * décision. Un opérateur qui double le quota d'un client ami ne doit
         * pas pouvoir le faire sans que le chiffre précédent soit conservé.
         */
        $this->audit->record(
            action: 'platform.plan.limits_changed',
            target: $plan,
            payload: [
                'plan' => $plan->key,
                'before' => $avant,
                'after' => $apres,
                'operator_id' => $this->platform->operatorId(),
                ...$effet,
            ],
        );

        return ApiResponse::success([
            ...$this->present($plan->refresh()),

            // Dit l'effet plutôt que de laisser l'opérateur le déduire : la
            // règle d'asymétrie n'est pas devinable depuis une réponse.
            'applied_now' => $effet['applied_now'],
            'applied_at_renewal' => $effet['applied_at_renewal'],
            'subscriptions_affected' => $effet['subscriptions'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Plan $plan): array
    {
        return [
            'key' => $plan->key,
            'name' => $plan->name,
            'limits' => (array) ($plan->limits ?? []),
            'is_public' => (bool) ($plan->is_public ?? true),
        ];
    }
}
