<?php

declare(strict_types=1);

namespace Modules\Payments\Presentation\Http\Controllers;

use App\Platform\Exceptions\DomainException;
use App\Platform\Http\ApiResponse;
use App\Platform\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Identity\Infrastructure\Auth\ApiKeyResolver;
use Modules\Payments\Application\Refunds\RequestRefund;
use Modules\Payments\Domain\Models\ExternalCharge;
use Modules\Payments\Domain\Models\PaymentIntent;
use Modules\Payments\Domain\Models\PaymentTransaction;
use Modules\Payments\Domain\Models\Refund;
use Modules\Payments\Presentation\Http\Requests\CreateRefundRequest;

/**
 * Rendre l'argent, pour un produit externe.
 *
 * ## `202` et non `201`
 *
 * Pour la même raison qu'à l'encaissement : ce qui est créé est une
 * **obligation**, pas un fait. L'argent n'est pas sorti — un décaissement
 * Mobile Money est un transfert, aujourd'hui exécuté à la main par un opérateur.
 *
 * Le produit apprend le décaissement réel par `refund.succeeded`, ou en sondant.
 * Traiter la réponse de cette route comme « l'argent est rendu » ferait annuler
 * une inscription que rien n'a encore remboursée.
 *
 * @see docs/03-services/payments/08-refunds.md
 */
final class RefundController
{
    public function __construct(private readonly ApiKeyResolver $keys) {}

    public function store(CreateRefundRequest $request, RequestRefund $refunds, string $chargeId): JsonResponse
    {
        $key = $this->keys->require($request, 'payments.refund');
        $charge = $this->charge($key->organizationId(), $chargeId);

        $intent = PaymentIntent::query()->find($charge->payment_intent_id);

        if ($intent === null) {
            throw DomainException::conflict(
                'PAYMENT_NOT_SETTLED',
                __('payments::messages.refund_payment_not_settled'),
            );
        }

        // Absent = tout ce qui reste. Obliger le produit à calculer le reliquat
        // lui ferait tenir une seconde comptabilité, qui finirait par diverger.
        $amount = $request->has('amount')
            ? Money::of($request->integer('amount'), $charge->currency)
            : $this->remaining($intent, $charge);

        $refund = $refunds->handle(
            intent: $intent,
            amount: $amount,
            reason: $request->string('reason')->toString(),
            requestedBy: $key->key->id,
            requestedVia: 'api_key',
            idempotencyKey: $request->header('Idempotency-Key'),
        );

        return ApiResponse::success($this->present($refund), status: 202);
    }

    /**
     * Sonder un remboursement — le même filet qu'à l'encaissement, et pour la
     * même raison : un webhook se perd.
     */
    public function show(Request $request, string $chargeId, string $refundId): JsonResponse
    {
        $key = $this->keys->require($request, 'payments.read');
        $charge = $this->charge($key->organizationId(), $chargeId);

        $refund = Refund::query()
            ->where('payment_intent_id', $charge->payment_intent_id)
            ->whereKey($refundId)
            ->first();

        if ($refund === null) {
            throw DomainException::notFound(
                'RESOURCE_NOT_FOUND',
                __('payments::messages.refund_not_found'),
            );
        }

        $response = ApiResponse::success($this->present($refund));

        if (! $refund->isSettled()) {
            $response->header('Retry-After', '30');
        }

        return $response;
    }

    /**
     * Les remboursements d'une charge, pour la réconciliation.
     */
    public function index(Request $request, string $chargeId): JsonResponse
    {
        $key = $this->keys->require($request, 'payments.read');
        $charge = $this->charge($key->organizationId(), $chargeId);

        $refunds = Refund::query()
            ->where('payment_intent_id', $charge->payment_intent_id)
            ->orderByDesc('created_at')
            ->get();

        return ApiResponse::success($refunds->map($this->present(...))->all());
    }

    /**
     * La charge doit appartenir à ce produit.
     *
     * `404` et non `403` : distinguer permettrait d'énumérer les charges des
     * autres produits, exactement comme pour une facture.
     */
    private function charge(string $organizationId, string $chargeId): ExternalCharge
    {
        $charge = ExternalCharge::query()
            ->where('organization_id', $organizationId)
            ->whereKey($chargeId)
            ->first();

        if ($charge === null) {
            throw DomainException::notFound(
                'RESOURCE_NOT_FOUND',
                __('payments::messages.external_charge_not_found'),
            );
        }

        return $charge;
    }

    /**
     * Ce qui reste remboursable : le brut encaissé, moins ce qui est engagé.
     *
     * Un remboursement échoué ne compte pas — rien n'est sorti, la somme
     * redevient disponible.
     */
    private function remaining(PaymentIntent $intent, ExternalCharge $charge): Money
    {
        $encaisse = (int) PaymentTransaction::query()
            ->where('payment_intent_id', $intent->id)
            ->where('type', PaymentTransaction::CHARGE)
            ->sum('amount');

        $engage = (int) Refund::query()
            ->where('payment_intent_id', $intent->id)
            ->whereIn('status', Refund::HOLDS_FUNDS)
            ->sum('amount');

        $reste = $encaisse - $engage;

        if ($reste <= 0) {
            throw DomainException::unprocessable(
                'REFUND_EXCEEDS_PAYMENT',
                __('payments::messages.refund_exceeds_payment', [
                    'available' => Money::of(0, $charge->currency)->format(),
                ]),
            );
        }

        return Money::of($reste, $charge->currency);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Refund $refund): array
    {
        return [
            'refund_id' => $refund->id,
            'payment_id' => $refund->payment_intent_id,
            'subject_type' => $refund->subject_type,
            'subject_id' => $refund->subject_id,

            // `pending` signifie **décidé, pas encore versé**. Le produit ne
            // doit pas fermer l'accès sur cette base : l'argent est toujours
            // sur le compte marchand.
            'status' => $refund->status,

            ...$refund->money()->toArray(),
            'reason' => $refund->reason,
            'failure_code' => $refund->failure_code,
            'created_at' => $refund->created_at?->toIso8601ZuluString(),
            'settled_at' => $refund->settled_at?->toIso8601ZuluString(),
        ];
    }
}
