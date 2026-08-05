<?php

declare(strict_types=1);

namespace Modules\Billing\Application\Invoicing;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Modules\Billing\Domain\Models\Invoice;
use Throwable;

/**
 * Mettre le PDF en page, hors de la transaction d'émission.
 *
 * ## Pourquoi une file
 *
 * Deux raisons, et la seconde compte davantage.
 *
 * Mettre en page prend du temps, et l'émission d'une facture ne doit pas
 * l'attendre.
 *
 * Surtout, **un échec de mise en page ne doit pas empêcher une facture
 * d'exister**. Une police manquante ou un gabarit fautif annuleraient sinon la
 * transaction entière — et avec elle l'abonnement qu'elle ouvre. Le document
 * est la trace d'une facture ; il n'en est pas la condition.
 *
 * @see docs/04-decisions/adr-0013-invoice-pdf-frozen.md
 */
final class RenderInvoicePdfJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(private readonly string $invoiceId) {}

    public function handle(RenderInvoicePdf $renderer): void
    {
        $invoice = Invoice::query()->find($this->invoiceId);

        if ($invoice === null) {
            return;
        }

        try {
            $renderer->handle($invoice);
        } catch (Throwable $e) {
            /*
             * **L'échec est avalé, délibérément.**
             *
             * La file peut être exécutée en mode synchrone — c'est le cas en
             * test, et ce peut l'être en production sur une petite
             * installation. L'exception remonterait alors jusqu'à l'appelant et
             * ferait échouer l'émission elle-même : un magasin mal configuré
             * empêcherait de facturer.
             *
             * C'est la faute que cette tâche existe pour éviter, et elle serait
             * invisible tant qu'un magasin répond.
             *
             * La reprise n'est donc pas confiée aux réessais de la file, mais à
             * `billing:invoice-pdf`, ordonnancée chaque nuit : elle voit toutes
             * les factures sans document, quelle qu'en soit la cause, y compris
             * celles émises avant l'existence du module de stockage.
             */
            Log::error('billing: mise en page de facture impossible', [
                'invoice_id' => $this->invoiceId,
                'raison' => $e->getMessage(),
            ]);
        }
    }
}
