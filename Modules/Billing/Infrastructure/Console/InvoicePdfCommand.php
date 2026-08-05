<?php

declare(strict_types=1);

namespace Modules\Billing\Infrastructure\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Modules\Billing\Application\Invoicing\RenderInvoicePdf;
use Modules\Billing\Domain\Models\Invoice;
use Throwable;

/**
 * Rattraper les factures sans PDF, ou en régénérer un.
 *
 * Sert deux cas. Les factures émises **avant** l'arrivée du module de stockage,
 * qui n'ont jamais eu de document. Et celles dont la mise en page a échoué en
 * file — un gabarit fautif, une police manquante.
 *
 * `--rebuild` attache un **nouveau** fichier et bascule la référence. L'ancien
 * demeure, avec sa rétention : le document envoyé au client reste consultable.
 * Écraser l'ancien serait exactement ce que l'ADR-0013 refuse, avec une
 * commande pour le commettre.
 *
 * @see docs/04-decisions/adr-0013-invoice-pdf-frozen.md
 */
final class InvoicePdfCommand extends Command
{
    protected $signature = 'billing:invoice-pdf
        {invoice? : Une facture en particulier}
        {--rebuild : Produit un nouveau document, sans effacer le precedent}
        {--limit=200 : Nombre maximal de factures traitees}';

    protected $description = 'Met en page les factures qui n\'ont pas encore de PDF.';

    public function handle(RenderInvoicePdf $renderer): int
    {
        $rebuild = (bool) $this->option('rebuild');
        $factures = $this->invoices($rebuild);

        if ($factures->isEmpty()) {
            $this->info('Aucune facture à mettre en page.');

            return self::SUCCESS;
        }

        $echecs = 0;

        foreach ($factures as $invoice) {
            try {
                $fileId = $rebuild ? $renderer->rebuild($invoice) : $renderer->handle($invoice);

                if ($fileId === null) {
                    $this->line("  <fg=yellow>–</> {$invoice->number} — non émise, rien à figer");

                    continue;
                }

                $this->line("  <fg=green>✓</> {$invoice->number}");
            } catch (Throwable $e) {
                $echecs++;

                // Une facture qui échoue n'arrête pas les autres : un
                // rattrapage interrompu au dixième document laisserait le
                // travail à moitié fait, sans qu'on sache où.
                $this->line("  <fg=red>✗</> {$invoice->number} — {$e->getMessage()}");
            }
        }

        $this->newLine();
        $this->info(sprintf('%d facture(s) traitée(s), %d échec(s).', $factures->count(), $echecs));

        return $echecs > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return Collection<int, Invoice>
     */
    private function invoices(bool $rebuild): mixed
    {
        $query = Invoice::query()->with('lines')->whereNotNull('issued_at');

        if ($id = $this->argument('invoice')) {
            return $query->where('id', $id)->orWhere('number', $id)->get();
        }

        // Sans identifiant, `--rebuild` refuserait de refaire toute la
        // comptabilité : la commande ne traite alors que ce qui manque.
        return $query->whereNull('pdf_file_id')
            ->orderBy('issued_at')
            ->limit((int) $this->option('limit'))
            ->get();
    }
}
