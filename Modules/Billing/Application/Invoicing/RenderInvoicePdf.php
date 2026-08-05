<?php

declare(strict_types=1);

namespace Modules\Billing\Application\Invoicing;

use App\Platform\Contracts\FileRef;
use App\Platform\Support\Money;
use Barryvdh\DomPDF\Facade\Pdf;
use Modules\Billing\Domain\Models\Invoice;
use Modules\Storage\Application\Files\StoreFile;

/**
 * Mettre une facture en page, une fois.
 *
 * ## Pourquoi pas à la demande
 *
 * Une facture émise est un document légal. Régénérée à chaque téléchargement,
 * elle suivrait le code du jour : un taux de TVA modifié, un numéro de
 * contribuable corrigé, un gabarit refait — et la facture de janvier,
 * téléchargée en décembre, ne serait plus celle qui a été envoyée.
 *
 * Personne ne s'en apercevrait. Aucun test ne peut l'attraper : le code produit
 * exactement ce qu'on lui demande. La divergence n'apparaît qu'en comparant
 * deux exemplaires du même document — lors d'un contrôle, ou d'un litige.
 *
 * @see docs/04-decisions/adr-0013-invoice-pdf-frozen.md
 */
final class RenderInvoicePdf
{
    public function __construct(private readonly StoreFile $files) {}

    public function handle(Invoice $invoice): ?string
    {
        /*
         * Une facture non émise n'a pas de PDF, et c'est le sens de la
         * décision : figer un brouillon reviendrait à figer quelque chose qui
         * n'engage encore personne.
         */
        if ($invoice->issued_at === null) {
            return null;
        }

        // Idempotent : un PDF déjà attaché n'est pas refait. La régénération
        // délibérée passe par `--rebuild`, qui produit un **nouveau** fichier.
        if ($invoice->pdf_file_id !== null) {
            return (string) $invoice->pdf_file_id;
        }

        return $this->render($invoice);
    }

    /**
     * Régénérer attache un nouveau fichier et bascule la référence.
     *
     * L'ancien demeure, avec sa rétention : le document envoyé au client reste
     * consultable. Une régénération qui écraserait l'ancien serait exactement
     * ce que l'ADR-0013 refuse, avec une commande pour la commettre.
     */
    public function rebuild(Invoice $invoice): ?string
    {
        if ($invoice->issued_at === null) {
            return null;
        }

        return $this->render($invoice);
    }

    private function render(Invoice $invoice): string
    {
        $invoice->loadMissing('lines');

        $html = view('billing::invoice', $this->data($invoice))->render();

        $file = $this->files->handle(
            owner: new FileRef(InvoiceFiles::TYPE, (string) $invoice->id),
            organizationId: (string) $invoice->organization_id,
            name: 'facture-'.$invoice->number.'.pdf',
            contents: (string) Pdf::loadHTML($html)->setPaper('a4')->output(),
            mimeType: 'application/pdf',
        );

        return (string) $file->id;
    }

    /**
     * Tout vient de `billing_details`, figé à l'émission — jamais de
     * l'organisation telle qu'elle est aujourd'hui.
     *
     * C'est là que se joue l'essentiel de la décision : lire l'organisation en
     * base ici suffirait à rendre la facture instable, alors même que le
     * fichier, lui, serait figé.
     *
     * @return array<string, mixed>
     */
    private function data(Invoice $invoice): array
    {
        $details = (array) ($invoice->billing_details ?? []);
        $currency = (string) $invoice->currency;

        return [
            'invoice' => $invoice,
            'locale' => (string) ($details['locale'] ?? app()->getLocale()),

            'issuer' => [
                'name' => (string) ($details['issuer']['name'] ?? config('sekuu.company.name', 'Sekuu')),
                'lines' => array_values(array_filter((array) ($details['issuer']['lines'] ?? config('sekuu.company.lines', [])))),
            ],

            'customer' => [
                'name' => (string) ($details['customer']['name'] ?? $details['name'] ?? '—'),
                'lines' => array_values(array_filter((array) ($details['customer']['lines'] ?? []))),
            ],

            'dates' => [
                'issued' => $invoice->issued_at?->toDateString() ?? '—',
                'due' => $invoice->due_at?->toDateString() ?? '—',
                'period' => $invoice->period_start && $invoice->period_end
                    ? $invoice->period_start->toDateString().' → '.$invoice->period_end->toDateString()
                    : '—',
            ],

            'lines' => $invoice->lines->map(fn ($line): array => [
                'description' => (string) $line->description,
                'quantity' => (string) $line->quantity,
                'unit_amount' => Money::of((int) $line->unit_amount, $currency)->format(),
                'amount' => Money::of((int) $line->amount, $currency)->format(),
            ])->all(),

            'totals' => [
                'subtotal' => Money::of((int) $invoice->subtotal, $currency)->format(),

                // Nul n'est pas zéro : une facture hors champ de TVA ne doit pas
                // afficher « TVA 0 % », qui affirme quelque chose de faux.
                'tax_rate' => $invoice->tax_rate > 0 ? rtrim(rtrim(number_format((float) $invoice->tax_rate, 2), '0'), '.').' %' : null,
                'tax' => Money::of((int) $invoice->tax_amount, $currency)->format(),

                'credit' => (int) $invoice->credit_applied > 0
                    ? Money::of((int) $invoice->credit_applied, $currency)->format()
                    : null,

                'total' => Money::of((int) $invoice->total, $currency)->format(),
            ],
        ];
    }
}
