<?php

declare(strict_types=1);

namespace Modules\Billing\Presentation\Http\Controllers;

use App\Platform\Exceptions\DomainException;
use App\Platform\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Billing\Application\Ledger\CreditLedger;
use Modules\Billing\Application\Subscriptions\ChangePlan;
use Modules\Billing\Application\Subscriptions\RenewSubscription;
use Modules\Billing\Application\Subscriptions\SubscribeToPlan;
use Modules\Billing\Domain\Models\Invoice;
use Modules\Billing\Domain\Models\Plan;
use Modules\Billing\Domain\Models\Subscription;
use Modules\Billing\Presentation\Http\Controllers\Concerns\EngagesTheOrganization;
use Modules\Billing\Presentation\Http\Requests\ChangePlanRequest;
use Modules\Billing\Presentation\Http\Requests\SubscribeRequest;

/**
 * Abonnement de l'organisation active.
 *
 * Le singulier est délibéré : une organisation n'a qu'un abonnement vivant.
 * `/subscriptions/{id}` n'existe pas, parce qu'il n'y a rien à choisir.
 *
 * @see docs/03-services/billing/03-api.md
 */
final class SubscriptionController
{
    use EngagesTheOrganization;

    public function __construct(private readonly CreditLedger $credit) {}

    /**
     * Ouvert à tout membre : un utilisateur doit pouvoir comprendre pourquoi
     * une fonctionnalité lui est refusée sans avoir à demander à son patron.
     */
    public function show(Request $request): JsonResponse
    {
        $organizationId = $this->organizationId();

        $subscription = Subscription::query()
            ->where('organization_id', $organizationId)
            ->alive()
            ->with(['plan', 'price', 'pendingPlan'])
            ->first();

        if ($subscription === null) {
            throw DomainException::notFound(
                'SUBSCRIPTION_NOT_FOUND',
                __('billing::messages.subscription_not_found'),
            );
        }

        return ApiResponse::success($this->present($subscription));
    }

    public function history(Request $request): JsonResponse
    {
        $subscriptions = Subscription::query()
            ->where('organization_id', $this->organizationId())
            ->with(['plan', 'price'])
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        return ApiResponse::success($subscriptions->map($this->present(...))->all());
    }

    public function store(SubscribeRequest $request, SubscribeToPlan $subscribe): JsonResponse
    {
        $this->requireBillingRole();

        $result = $subscribe->handle(
            organizationId: $this->organizationId(),
            planKey: $request->string('plan_key')->toString(),
            priceId: $request->input('price_id'),
            userId: $this->userId(),
        );

        return ApiResponse::created([
            'subscription' => $this->present($result['subscription']->load(['plan', 'price'])),
            'invoice' => $result['invoice'] === null ? null : $this->presentInvoice($result['invoice']),
        ]);
    }

    public function change(ChangePlanRequest $request, ChangePlan $change): JsonResponse
    {
        $this->requireBillingRole();

        $subscription = $this->aliveSubscription($request);
        $plan = Plan::query()->active()->where('key', $request->string('plan_key')->toString())->first();

        if ($plan === null) {
            throw DomainException::notFound('PLAN_NOT_FOUND', __('billing::messages.plan_not_found'));
        }

        $price = $plan->prices()->active()->when(
            $request->filled('price_id'),
            fn ($q) => $q->whereKey($request->input('price_id')),
            fn ($q) => $q->where('interval', $subscription->price->interval),
        )->first();

        if ($price === null) {
            throw DomainException::unprocessable(
                'CURRENCY_NOT_SUPPORTED',
                __('billing::messages.price_not_available'),
            );
        }

        $result = $change->handle($subscription, $plan, $price, $this->currentUsage($subscription));

        return ApiResponse::success([
            'direction' => $result['direction'],
            'effective' => $result['effective'],
            'credit_applied' => $result['credit_applied'],
            'invoice' => $result['invoice'] === null ? null : $this->presentInvoice($result['invoice']),
        ]);
    }

    /**
     * L'acte volontaire qui remplace la reconduction tacite : il n'existe aucun
     * moyen technique de prélever un client en Mobile Money.
     */
    public function renew(Request $request, RenewSubscription $renew): JsonResponse
    {
        $this->requireBillingRole();

        $invoice = $renew->handle($this->aliveSubscription($request, includeSuspended: true));

        return ApiResponse::created($this->presentInvoice($invoice));
    }

    /**
     * Résilier ne coupe pas l'accès : la période est payée. Couper
     * immédiatement obligerait à rembourser.
     */
    public function cancel(Request $request): JsonResponse
    {
        $this->requireBillingRole();

        $subscription = $this->aliveSubscription($request);

        $subscription->forceFill([
            'cancel_at_period_end' => true,
            'cancelled_at' => now(),
            // Motif libre et facultatif : une liste fermée produit des
            // statistiques flatteuses et sans valeur.
            'cancellation_reason' => $request->input('reason'),
        ])->save();

        return ApiResponse::success($this->present($subscription->fresh(['plan', 'price'])));
    }

    public function resume(Request $request): JsonResponse
    {
        $this->requireBillingRole();

        $subscription = $this->aliveSubscription($request);

        $subscription->forceFill([
            'cancel_at_period_end' => false,
            'cancelled_at' => null,
            'cancellation_reason' => null,
        ])->save();

        return ApiResponse::success($this->present($subscription->fresh(['plan', 'price'])));
    }

    private function aliveSubscription(Request $request, bool $includeSuspended = false): Subscription
    {
        $subscription = Subscription::query()
            ->where('organization_id', $this->organizationId())
            ->when(
                $includeSuspended,
                fn ($q) => $q->whereNotIn('status', ['expired']),
                fn ($q) => $q->alive(),
            )
            ->with(['plan', 'price'])
            ->orderByDesc('created_at')
            ->first();

        return $subscription ?? throw DomainException::notFound(
            'SUBSCRIPTION_NOT_FOUND',
            __('billing::messages.subscription_not_found'),
        );
    }

    /**
     * Usage courant, pour refuser une descente en gamme destructrice.
     *
     * Billing ne compte pas lui-même : chaque module sait compter le sien. Ici
     * seuls les membres et les workspaces sont connus d'Identity, et c'est déjà
     * ce qui casserait en premier.
     *
     * @return array<string, int>
     */
    private function currentUsage(Subscription $subscription): array
    {
        return [
            'members' => DB::table('memberships')
                ->where('organization_id', $subscription->organization_id)
                ->where('status', 'active')
                ->count(),
            'workspaces' => DB::table('workspaces')
                ->where('organization_id', $subscription->organization_id)
                ->whereNull('deleted_at')
                ->count(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Subscription $subscription): array
    {
        return [
            'id' => $subscription->id,
            'status' => $subscription->status->value,
            'plan' => [
                'key' => $subscription->plan->key,
                'name' => $subscription->plan->name,
                'limits' => $subscription->plan->limits ?? [],
            ],
            'price' => [
                'interval' => $subscription->price->interval,
                ...$subscription->price->money()->toArray(),
            ],
            'current_period_start' => $subscription->current_period_start->toIso8601ZuluString(),
            'current_period_end' => $subscription->current_period_end->toIso8601ZuluString(),
            'trial_ends_at' => $subscription->trial_ends_at?->toIso8601ZuluString(),
            'grace_ends_at' => $subscription->grace_ends_at?->toIso8601ZuluString(),
            'cancel_at_period_end' => $subscription->cancel_at_period_end,
            'pending_plan' => $subscription->pendingPlan?->key,

            // Confort d'affichage, **pas** une autorisation : un client qui s'en
            // sert pour ouvrir une fonctionnalité fait le mauvais choix. La
            // source de vérité est `organization_products`, côté Identity.
            'access_open' => $subscription->grantsAccess(),

            'credit_balance' => $this->credit
                ->balance($subscription->organization_id, $subscription->price->currency)
                ->toArray(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentInvoice(Invoice $invoice): array
    {
        return [
            'id' => $invoice->id,
            'number' => $invoice->number,
            'status' => $invoice->status,
            'total' => $invoice->total,
            'currency' => $invoice->currency,
            'due_at' => $invoice->due_at?->toIso8601ZuluString(),
        ];
    }
}
