<?php

declare(strict_types=1);

namespace Modules\Billing\Presentation\Http\Controllers;

use App\Platform\Exceptions\DomainException;
use App\Platform\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Billing\Application\Payments\InitiatePayment;
use Modules\Billing\Domain\Models\Invoice;
use Modules\Billing\Domain\Models\PaymentAttempt;
use Modules\Billing\Domain\Models\PaymentIntent;
use Modules\Billing\Presentation\Http\Requests\CreatePaymentRequest;
use Modules\Billing\Presentation\Support\ResolvesOrganization;

/**
 * Paiement Mobile Money.
 *
 * @see docs/04-decisions/adr-0008-payment-aggregators-failover.md
 */
final class PaymentController
{
    use ResolvesOrganization;

    public function store(CreatePaymentRequest $request, InitiatePayment $payments): JsonResponse
    {
        $context = $this->requireBillingRole($request);
        $organizationId = $this->organizationId($request);

        $invoice = Invoice::query()
            ->where('organization_id', $organizationId)
            ->whereKey($request->string('invoice_id')->toString())
            ->first();

        if ($invoice === null) {
            throw DomainException::notFound('INVOICE_NOT_FOUND', __('billing::messages.invoice_not_found'));
        }

        // Ni le montant ni l'agrégateur ne viennent du corps : le premier vient
        // de la facture, le second est un détail d'exploitation dont
        // l'exposition figerait l'ordre de priorité dans les interfaces.
        $intent = $payments->handle(
            invoice: $invoice,
            rawMsisdn: $request->string('msisdn')->toString(),
            idempotencyKey: $request->header('Idempotency-Key'),
            initiatedBy: $context->user->id,
        );

        // 202 et non 201 : ce qui est créé est une **intention**. Le client doit
        // ensuite interroger `GET /payments/{id}`.
        return ApiResponse::success($this->present($intent, detailed: true), status: 202);
    }

    public function show(Request $request, string $paymentId): JsonResponse
    {
        $intent = PaymentIntent::query()
            ->where('organization_id', $this->organizationId($request))
            ->whereKey($paymentId)
            ->with('attempts')
            ->first();

        if ($intent === null) {
            throw DomainException::notFound(
                'RESOURCE_NOT_FOUND',
                __('billing::messages.payment_not_found'),
            );
        }

        // Les tentatives ne sont exposées qu'aux rôles de facturation : l'ordre
        // de priorité des agrégateurs est une information d'exploitation.
        $detailed = $this->hasBillingRole($request);

        $response = ApiResponse::success($this->present($intent, $detailed));

        // Sonder est le mode d'emploi de cet endpoint : le dire dans l'en-tête
        // évite que chaque client invente son propre rythme.
        if (! $intent->isSettled()) {
            $response->header('Retry-After', '5');
        }

        return $response;
    }

    public function index(Request $request): JsonResponse
    {
        $intents = PaymentIntent::query()
            ->where('organization_id', $this->organizationId($request))
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        return ApiResponse::success($intents->map(fn (PaymentIntent $i) => $this->present($i))->all());
    }

    private function hasBillingRole(Request $request): bool
    {
        try {
            $this->requireBillingRole($request);

            return true;
        } catch (DomainException) {
            return false;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function present(PaymentIntent $intent, bool $detailed = false): array
    {
        $payload = [
            'id' => $intent->id,
            'status' => $intent->status,
            'operator' => $intent->operator,
            'invoice_id' => $intent->invoice_id,
            ...$intent->money()->toArray(),
            'expires_at' => $intent->expires_at->toIso8601ZuluString(),
            'failure_code' => $intent->failure_code,
        ];

        if ($intent->status === PaymentIntent::PENDING) {
            $payload['instructions'] = __('billing::messages.payment_instructions');
        }

        if (! $detailed) {
            return $payload;
        }

        $payload['attempts'] = $intent->attempts->map(fn (PaymentAttempt $attempt): array => [
            'provider' => $attempt->provider,
            'status' => $attempt->status->value,

            // Explique pourquoi la bascule s'est arrêtée là : une fois l'invite
            // partie, on n'essaie plus ailleurs.
            'customer_prompted' => $attempt->customer_prompted,

            'failure_code' => $attempt->failure_code,
            'started_at' => $attempt->started_at->toIso8601ZuluString(),
        ])->all();

        return $payload;
    }
}
