<?php

declare(strict_types=1);

namespace Modules\Billing\Presentation\Http\Controllers;

use App\Platform\Contracts\FileActor;
use App\Platform\Exceptions\DomainException;
use App\Platform\Http\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Modules\Billing\Application\Invoicing\RenderInvoicePdf;
use Modules\Billing\Domain\Models\Invoice;
use Modules\Billing\Domain\Models\InvoiceLine;
use Modules\Billing\Presentation\Http\Controllers\Concerns\EngagesTheOrganization;
use Modules\Storage\Application\Files\IssueReadUrl;
use Modules\Storage\Domain\Models\StoredFile;

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
    use EngagesTheOrganization;

    public function index(Request $request): JsonResponse
    {
        $query = Invoice::query()
            ->where('organization_id', $this->organizationId())
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
            ->where('organization_id', $this->organizationId())
            ->whereKey($invoiceId)
            ->with('lines')
            ->first();

        if ($invoice === null) {
            throw DomainException::notFound('INVOICE_NOT_FOUND', __('billing::messages.invoice_not_found'));
        }

        return ApiResponse::success($this->present($invoice, withLines: true));
    }

    /**
     * Une redirection vers une URL signée, jamais les octets.
     *
     * `302` garde la règle posée par l'ADR-0012 : les octets ne traversent pas
     * la plateforme. Le navigateur suit la redirection sans le savoir et
     * télécharge depuis le magasin. Un client d'API qui préfère l'URL brute a
     * `GET /files/{id}/url`.
     *
     * Le PDF est celui qui a été produit à l'émission, et jamais un rendu du
     * jour — voir ADR-0013.
     */
    public function download(
        Request $request,
        IssueReadUrl $urls,
        RenderInvoicePdf $renderer,
        string $invoiceId,
    ): RedirectResponse {
        $organizationId = $this->organizationId();

        $invoice = Invoice::query()
            ->where('organization_id', $organizationId)
            ->where('id', $invoiceId)
            ->first();

        if ($invoice === null) {
            throw DomainException::notFound('INVOICE_NOT_FOUND', __('billing::messages.invoice_not_found'));
        }

        /*
         * Rattrapage synchrone si la file a échoué, ou si la facture est
         * antérieure à l'arrivée du module de stockage.
         *
         * Ce n'est pas une génération à la demande : le résultat est **attaché
         * et figé**, et la prochaine visite servira ce fichier-là. Le document
         * ne sera simplement pas identique à ce que le client a vu à l'époque —
         * il n'y avait rien à voir.
         */
        $fileId = $invoice->pdf_file_id ?? $renderer->handle($invoice);

        if ($fileId === null) {
            throw DomainException::conflict(
                'INVOICE_NOT_ISSUED',
                __('billing::messages.invoice_pdf_unavailable'),
            );
        }

        $file = StoredFile::query()->with('destination')->find($fileId);

        if ($file === null) {
            throw DomainException::notFound('FILE_NOT_FOUND', __('billing::messages.invoice_pdf_unavailable'));
        }

        $issued = $urls->handle($file, FileActor::user($this->userId(), $organizationId), $request->ip());

        return new RedirectResponse($issued->url);
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
