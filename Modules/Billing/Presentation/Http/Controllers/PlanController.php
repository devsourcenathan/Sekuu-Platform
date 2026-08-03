<?php

declare(strict_types=1);

namespace Modules\Billing\Presentation\Http\Controllers;

use App\Platform\Exceptions\DomainException;
use App\Platform\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Modules\Billing\Domain\Models\Plan;
use Modules\Billing\Domain\Models\PlanPrice;

/**
 * Catalogue.
 *
 * **Public, sans authentification** : une page de tarifs doit être lisible
 * avant d'avoir un compte.
 *
 * Il n'y a pas de `POST /plans`. Les plans sont versionnés avec le code, comme
 * les templates de plateforme de Notify : un tarif ne se modifie pas depuis un
 * formulaire, et changer un prix consiste à archiver l'ancien tarif — une
 * opération de migration, pas d'API.
 *
 * @see docs/03-services/billing/03-api.md
 */
final class PlanController
{
    public function index(): JsonResponse
    {
        // Les plans non publics n'apparaissent jamais, y compris pour
        // l'organisation qui en bénéficie : leur existence est une information
        // commerciale.
        $plans = Plan::query()
            ->active()
            ->where('is_public', true)
            ->with(['prices' => fn ($q) => $q->active(), 'products'])
            ->orderBy('sort_order')
            ->get();

        return ApiResponse::success($plans->map($this->present(...))->all());
    }

    public function show(string $key): JsonResponse
    {
        $plan = Plan::query()
            ->active()
            ->where('is_public', true)
            ->where('key', $key)
            ->with(['prices' => fn ($q) => $q->active(), 'products'])
            ->first();

        if ($plan === null) {
            throw DomainException::notFound('PLAN_NOT_FOUND', __('billing::messages.plan_not_found'));
        }

        return ApiResponse::success($this->present($plan));
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Plan $plan): array
    {
        return [
            'key' => $plan->key,
            'name' => $plan->name,
            'description' => $plan->description,
            'trial_days' => $plan->trial_days,
            'products' => $plan->products->pluck('product_id')->all(),
            'limits' => $plan->limits ?? [],
            'prices' => $plan->prices->map(fn (PlanPrice $price): array => [
                'id' => $price->id,
                'interval' => $price->interval,
                'interval_count' => $price->interval_count,
                // Hors taxes, en plus petite unité. L'exposant est renvoyé pour
                // qu'aucun client n'ait à deviner que le XAF n'a pas de centime.
                ...$price->money()->toArray(),
            ])->all(),
        ];
    }
}
