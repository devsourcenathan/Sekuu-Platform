<?php

declare(strict_types=1);

namespace Modules\Billing\Presentation\Http\Controllers;

use App\Platform\Contracts\PayableRef;
use App\Platform\Contracts\PayerContext;
use App\Platform\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Modules\Billing\Application\Invoicing\InvoicePayable;
use Modules\Billing\Presentation\Http\Controllers\Concerns\EngagesTheOrganization;
use Modules\Billing\Presentation\Http\Requests\PayInvoiceRequest;
use Modules\Payments\Application\Payments\InitiatePayment;

/**
 * Règlement d'une facture.
 *
 * Vit dans Billing et non dans Payments : déclencher un paiement suppose de
 * savoir **ce qu'on paie**, combien cela vaut, et qui a le droit de le régler.
 * Payments ne sait rien de tout cela — et une route de création exposée là-bas
 * offrirait un moyen de faire sonner le téléphone de quelqu'un sans motif.
 *
 * @see docs/03-services/billing/03-api.md
 */
final class InvoicePaymentController
{
    use EngagesTheOrganization;

    public function __invoke(PayInvoiceRequest $request, InitiatePayment $payments): JsonResponse
    {
        $this->requireBillingRole();

        // Ni le montant ni l'agrégateur ne viennent du corps. Le contrôleur ne
        // charge même pas la facture : c'est `InvoicePayable` qui la lit,
        // vérifie que ce payeur a le droit de la régler, et produit le montant.
        $intent = $payments->handle(
            subject: new PayableRef(InvoicePayable::TYPE, $request->string('invoice_id')->toString()),
            payer: PayerContext::organization($this->organizationId(), $this->userId()),
            rawMsisdn: $request->string('msisdn')->toString(),
            idempotencyKey: $request->header('Idempotency-Key'),
        );

        // 202 et non 201 : ce qui est créé est une **intention**. Le client doit
        // ensuite sonder `GET /payments/{id}`.
        return ApiResponse::success([
            'id' => $intent->id,
            'status' => $intent->status,
            'operator' => $intent->operator,
            ...$intent->money()->toArray(),
            'expires_at' => $intent->expires_at->toIso8601ZuluString(),
            'instructions' => __('payments::messages.payment_instructions'),
        ], status: 202);
    }
}
