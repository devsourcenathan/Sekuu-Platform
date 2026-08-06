<?php

declare(strict_types=1);

namespace Modules\AI\Presentation\Http\Controllers;

use App\Platform\Contracts\BillingContract;
use App\Platform\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\AI\Application\Generation\SpendLedger;
use Modules\AI\Domain\Models\AiGeneration;
use Modules\AI\Presentation\Http\Concerns\ResolvesAiActor;

/**
 * Ce que l'organisation a consommé ce mois-ci.
 *
 * ## Les deux natures de coût sont séparées, jamais additionnées
 *
 * Celui de nos comptes est exact et compte pour le quota ; celui des comptes du
 * client est estimé à partir des prix publics, et ne lui est pas opposé — il le
 * paie à son fournisseur. Un total les mêlant ne voudrait rien dire, et
 * servirait quand même de base à une décision.
 *
 * ## `by_task` est le seul endroit où un client voit **où** part son budget
 *
 * C'est ce qui rend une conversation possible quand une facture surprend, et ce
 * qui permet à un produit de découvrir qu'une tâche appelée en boucle lui coûte
 * plus que tout le reste.
 *
 * @see docs/03-services/ai/03-api.md
 */
final class UsageController
{
    use ResolvesAiActor;

    public function __construct(
        private readonly SpendLedger $ledger,
        private readonly BillingContract $billing,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $organizationId = $this->callerOrganizationId($request, self::READ);
        $period = $this->ledger->period();

        $row = DB::table('ai_spend')
            ->where('organization_id', $organizationId)
            ->where('period', $period)
            ->first();

        $limit = $organizationId === null
            ? null
            : $this->billing->limit($organizationId, SpendLedger::QUOTA_KEY);

        return ApiResponse::success([
            'period' => $period,
            'generations' => (int) ($row->generations ?? 0),

            'platform' => [
                'cost_micros' => (int) ($row->cost_micros ?? 0),
                'estimated' => false,
            ],

            'own_accounts' => [
                'cost_micros' => (int) ($row->cost_micros_byo ?? 0),
                'estimated' => true,
            ],

            'limit' => [
                // `covered` à faux signifie que le plan n'ouvre pas la
                // ressource — ce qui n'est pas un refus. Un quota borne un usage
                // autorisé, il ne décide pas de l'autorisation.
                'covered' => $limit?->covered ?? false,
                'credits' => $limit?->value,
                'remaining' => $this->ledger->remaining($organizationId),
            ],

            'by_task' => $this->byTask($organizationId),
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function byTask(?string $organizationId): array
    {
        if ($organizationId === null) {
            return [];
        }

        return DB::table('ai_generations')
            ->select('task', DB::raw('count(*) as generations'), DB::raw('coalesce(sum(cost_micros), 0) as cost_micros'))
            ->where('organization_id', $organizationId)
            ->where('created_at', '>=', now()->startOfMonth())
            ->whereIn('status', [AiGeneration::SUCCEEDED, AiGeneration::FAILED])
            ->groupBy('task')
            ->orderByDesc('cost_micros')
            ->get()
            ->map(fn ($row): array => [
                'task' => (string) $row->task,
                'generations' => (int) $row->generations,
                'cost_micros' => (int) $row->cost_micros,
            ])
            ->all();
    }
}
