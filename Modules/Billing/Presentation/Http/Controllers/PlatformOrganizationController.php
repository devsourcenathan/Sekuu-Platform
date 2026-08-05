<?php

declare(strict_types=1);

namespace Modules\Billing\Presentation\Http\Controllers;

use App\Platform\Exceptions\DomainException;
use App\Platform\Http\ApiResponse;
use App\Platform\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Billing\Domain\Models\Invoice;
use Modules\Billing\Domain\Models\Subscription;

/**
 * Les organisations, vues par un opérateur.
 *
 * ## Ce que cette classe ne rend jamais
 *
 * Le contenu de ce que les clients nous confient : ni un fichier, ni un prompt,
 * ni le corps d'une notification. Un opérateur voit des **métadonnées et des
 * montants** — il constate qu'un fichier de 4 Mo existe, il ne l'ouvre pas.
 *
 * C'est la frontière qui empêche la promesse de confidentialité de Storage et
 * d'AI de n'être qu'une discipline.
 *
 * @see docs/04-decisions/adr-0018-platform-operator.md
 */
final class PlatformOrganizationController
{
    public function index(Request $request): JsonResponse
    {
        $recherche = $request->string('q')->toString();

        $organizations = DB::table('organizations')
            ->when($recherche !== '', fn ($q) => $q->where('name', 'ilike', '%'.$recherche.'%'))
            ->orderBy('created_at')
            ->limit(100)
            ->get(['id', 'name', 'slug', 'created_at']);

        return ApiResponse::success($organizations->map(fn ($o): array => [
            'id' => $o->id,
            'name' => $o->name,
            'slug' => $o->slug,
            'created_at' => $o->created_at,
        ])->all());
    }

    public function show(string $organizationId): JsonResponse
    {
        $organization = DB::table('organizations')->where('id', $organizationId)->first();

        if ($organization === null) {
            throw DomainException::notFound('ORGANIZATION_NOT_FOUND', __('billing::messages.organization_not_found'));
        }

        $subscription = Subscription::query()
            ->where('organization_id', $organizationId)
            ->alive()
            ->with('plan')
            ->first();

        return ApiResponse::success([
            'id' => $organization->id,
            'name' => $organization->name,
            'members' => DB::table('memberships')
                ->where('organization_id', $organizationId)
                ->where('status', 'active')
                ->count(),

            'subscription' => $subscription === null ? null : [
                'plan' => $subscription->plan?->key,
                'status' => $subscription->status->value,
                'period_end' => $subscription->current_period_end?->toIso8601String(),

                /*
                 * Ce qui a été **promis**, pas ce que le catalogue dit
                 * aujourd'hui.
                 *
                 * C'est précisément la question qu'un support se pose quand un
                 * client demande pourquoi il est bloqué : « le plan dit 50 » est
                 * une réponse fausse depuis l'ADR-0019.
                 */
                'granted_limits' => (array) ($subscription->granted_limits ?? []),
                'limits_granted_at' => $subscription->limits_granted_at?->toIso8601String(),
            ],
        ]);
    }

    /**
     * Les factures : des montants et des états, jamais les octets du PDF.
     */
    public function invoices(string $organizationId): JsonResponse
    {
        $invoices = Invoice::query()
            ->where('organization_id', $organizationId)
            ->orderByDesc('issued_at')
            ->limit(50)
            ->get();

        return ApiResponse::success($invoices->map(fn (Invoice $i): array => [
            'id' => $i->id,
            'number' => $i->number,
            'status' => $i->status,
            'total' => Money::of((int) $i->total, (string) $i->currency)->format(),
            'issued_at' => $i->issued_at?->toIso8601String(),
            'paid_at' => $i->paid_at?->toIso8601String(),

            // L'existence du document, pas son contenu. Un opérateur qui doit
            // le lire demande une copie au client.
            'has_pdf' => $i->pdf_file_id !== null,
        ])->all());
    }
}
