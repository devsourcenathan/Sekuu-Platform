<?php

declare(strict_types=1);

namespace Modules\Billing\Presentation\Http\Controllers;

use App\Platform\Exceptions\DomainException;
use App\Platform\Http\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Billing\Domain\Models\Invoice;
use Modules\Billing\Domain\Models\InvoiceLine;
use Modules\Billing\Presentation\Support\ResolvesOrganization;

/**
 * Factures.
 *
 * Ni `POST` ni `DELETE` : une facture est la conséquence d'une souscription,
 * d'un changement de plan ou d'un renouvellement, et une facture émise
 * s'annule — elle ne se supprime pas. La numérotation doit rester sans trou.
 *
 * @see docs/03-services/billing/03-api.md
 */
final class InvoiceController
{
    use ResolvesOrganization;

    public function index(Request $request): JsonResponse
    {
        $query = Invoice::query()
            ->where('organization_id', $this->organizationId($request))
            ->orderByDesc('issued_at')
            ->orderByDesc('id');

        $this->applyFilters($request, $query);

        $paginator = $query->cursorPaginate($this->perPage($request));

        return ApiResponse::success(
            $paginator->getCollection()->map(fn (Invoice $i) => $this->present($i))->all(),
            [
                'per_page' => $paginator->perPage(),
                'next_cursor' => $paginator->nextCursor()?->encode(),
                'has_more' => $paginator->hasMorePages(),
            ],
        );
    }

    public function show(Request $request, string $invoiceId): JsonResponse
    {
        $invoice = Invoice::query()
            ->where('organization_id', $this->organizationId($request))
            ->whereKey($invoiceId)
            ->with('lines')
            ->first();

        if ($invoice === null) {
            throw DomainException::notFound('INVOICE_NOT_FOUND', __('billing::messages.invoice_not_found'));
        }

        return ApiResponse::success($this->present($invoice, withLines: true));
    }

    /**
     * Le PDF appartient à Storage, qui n'existe pas encore.
     *
     * `503` franc plutôt qu'un PDF généré à la volée dont personne ne garantit
     * qu'il sera identique demain — pour un document légal, c'est un problème.
     */
    public function download(Request $request, string $invoiceId): JsonResponse
    {
        $this->organizationId($request);

        throw new DomainException(
            'SERVICE_UNAVAILABLE',
            __('billing::messages.invoice_pdf_unavailable'),
            503,
        );
    }

    private function applyFilters(Request $request, Builder $query): void
    {
        $filters = $request->query('filter', []);

        if (! is_array($filters)) {
            throw new DomainException('INVALID_FILTER', __('platform.filter_malformed'), 400);
        }

        foreach ($filters as $field => $value) {
            match ($field) {
                'status' => $query->where('status', $value),
                'number' => $query->where('number', 'like', '%'.$value.'%'),
                default => throw new DomainException(
                    'INVALID_FILTER',
                    __('platform.filter_unknown', ['field' => (string) $field]),
                    400,
                ),
            };
        }
    }

    private function perPage(Request $request): int
    {
        $perPage = (int) $request->query('per_page', (string) config('sekuu.pagination.per_page'));

        return max(1, min($perPage, (int) config('sekuu.pagination.max_per_page')));
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Invoice $invoice, bool $withLines = false): array
    {
        $payload = [
            'id' => $invoice->id,
            'number' => $invoice->number,
            'status' => $invoice->status,
            'currency' => $invoice->currency,
            'currency_exponent' => $invoice->totalMoney()->exponent(),
            'subtotal' => $invoice->subtotal,

            // Figé à l'émission : une facture passée continue d'afficher ce qui
            // a été réellement facturé, même si le taux a changé depuis.
            'tax_rate' => $invoice->tax_rate,

            'tax_amount' => $invoice->tax_amount,
            'credit_applied' => $invoice->credit_applied,
            'total' => $invoice->total,
            'amount_paid' => $invoice->amount_paid,
            'issued_at' => $invoice->issued_at->toIso8601ZuluString(),
            'due_at' => $invoice->due_at?->toIso8601ZuluString(),
            'paid_at' => $invoice->paid_at?->toIso8601ZuluString(),
        ];

        if (! $withLines) {
            return $payload;
        }

        // Le crédit apparaît comme une **ligne**, pas comme une soustraction
        // silencieuse : un client doit pouvoir vérifier son total à la main.
        $payload['lines'] = $invoice->lines->map(fn (InvoiceLine $line): array => [
            'description' => $line->description,
            'quantity' => $line->quantity,
            'unit_amount' => $line->unit_amount,
            'amount' => $line->amount,
        ])->all();

        return $payload;
    }
}
