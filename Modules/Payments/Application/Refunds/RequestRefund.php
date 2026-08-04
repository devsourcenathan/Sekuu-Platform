<?php

declare(strict_types=1);

namespace Modules\Payments\Application\Refunds;

use App\Platform\Contracts\PayableRef;
use App\Platform\Contracts\RefundableSource;
use App\Platform\Exceptions\DomainException;
use App\Platform\Support\Money;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Modules\Payments\Application\Payments\PayableRegistry;
use Modules\Payments\Domain\Models\PaymentIntent;
use Modules\Payments\Domain\Models\PaymentTransaction;
use Modules\Payments\Domain\Models\Refund;

/**
 * Décider de rendre l'argent.
 *
 * **Décider, pas décaisser.** Cette classe n'écrit rien au registre de caisse et
 * ne fait sortir aucun franc : elle enregistre une obligation, que
 * [SettleRefund] constatera quand l'argent aura réellement bougé.
 *
 * La séparation n'est pas décorative. Un décaissement Mobile Money est lent et
 * souvent manuel ; écrire la ligne de registre à la décision ferait dire au
 * registre qu'un argent est sorti alors qu'il est encore sur le compte
 * marchand — et un registre append-only ne se corrige pas.
 *
 * @see docs/03-services/payments/08-refunds.md
 */
final class RequestRefund
{
    public function __construct(private readonly PayableRegistry $payables) {}

    public function handle(
        PaymentIntent $intent,
        Money $amount,
        string $reason,
        ?string $requestedBy = null,
        string $requestedVia = 'api',
        ?string $idempotencyKey = null,
    ): Refund {
        $existing = $this->existing($intent, $idempotencyKey);

        if ($existing !== null) {
            return $existing;
        }

        $this->guardCurrency($intent, $amount);

        // Avant d'interroger le propriétaire : sur un paiement jamais abouti,
        // son refus porterait sur l'objet — « cette charge n'existe pas » — et
        // masquerait la vraie cause. Le contrôle qui fait autorité reste sous
        // verrou, dans la transaction ; celui-ci ne sert qu'à répondre juste.
        $this->guardSettled($intent);

        $this->guardOwnerAgrees($intent, $amount);

        return $this->create($intent, $amount, $reason, $requestedBy, $requestedVia, $idempotencyKey);
    }

    private function guardSettled(PaymentIntent $intent): void
    {
        if ($intent->status !== PaymentIntent::SUCCEEDED) {
            throw DomainException::conflict(
                'PAYMENT_NOT_SETTLED',
                __('payments::messages.refund_payment_not_settled'),
            );
        }
    }

    /**
     * Le propriétaire de l'objet tranche.
     *
     * La couche de paiement ne peut pas savoir si un remboursement est justifié
     * — la formation a-t-elle été suivie, le délai de rétractation est-il
     * écoulé ? C'est la même inversion que pour le prix.
     *
     * Ne pas porter `RefundableSource` **est une réponse** : c'est celle de
     * Billing, dont les trop-perçus deviennent des crédits. L'échec est donc dur
     * et explicite, jamais un repli silencieux.
     */
    private function guardOwnerAgrees(PaymentIntent $intent, Money $amount): void
    {
        if (! $this->payables->knows($intent->subject_type)) {
            throw DomainException::unprocessable(
                'PAYABLE_TYPE_UNKNOWN',
                __('payments::messages.payable_type_unknown', ['type' => $intent->subject_type]),
            );
        }

        $source = $this->payables->for($intent->subject_type);

        if (! $source instanceof RefundableSource) {
            throw DomainException::conflict(
                'REFUND_NOT_SUPPORTED',
                __('payments::messages.refund_not_supported', ['type' => $intent->subject_type]),
            );
        }

        $decision = $source->refundable(
            new PayableRef($intent->subject_type, $intent->subject_id),
            $amount,
        );

        if (! $decision->allowed) {
            throw DomainException::conflict(
                (string) $decision->refusalCode,
                (string) $decision->refusalMessage,
            );
        }
    }

    /**
     * L'invariant que la couche de paiement garde pour elle.
     *
     * **On ne rend jamais plus que ce qui a été réellement encaissé.** Aucun
     * produit n'a à en décider, et aucun ne doit pouvoir s'en affranchir.
     *
     * Le plafond est le montant de la ligne `charge` du registre — le constat —
     * et non celui de l'intention. Les deux devraient coïncider ; s'ils
     * divergent, c'est le registre qui dit ce qui est entré en caisse.
     *
     * Le verrou sur l'intention est indispensable : deux demandes concurrentes
     * de 15 000 sur un paiement de 20 000 passeraient toutes deux la
     * vérification, et 30 000 sortiraient.
     */
    private function create(
        PaymentIntent $intent,
        Money $amount,
        string $reason,
        ?string $requestedBy,
        string $requestedVia,
        ?string $idempotencyKey,
    ): Refund {
        try {
            return DB::transaction(function () use (
                $intent, $amount, $reason, $requestedBy, $requestedVia, $idempotencyKey
            ): Refund {
                $locked = PaymentIntent::query()->lockForUpdate()->findOrFail($intent->id);

                if ($locked->status !== PaymentIntent::SUCCEEDED) {
                    throw DomainException::conflict(
                        'PAYMENT_NOT_SETTLED',
                        __('payments::messages.refund_payment_not_settled'),
                    );
                }

                $disponible = $this->refundable($locked, $amount->currency);

                if ($amount->amount > $disponible->amount) {
                    throw DomainException::unprocessable(
                        'REFUND_EXCEEDS_PAYMENT',
                        __('payments::messages.refund_exceeds_payment', [
                            'available' => $disponible->format(),
                        ]),
                    );
                }

                return Refund::create([
                    'payment_intent_id' => $locked->id,
                    'subject_type' => $locked->subject_type,
                    'subject_id' => $locked->subject_id,
                    'amount' => $amount->amount,
                    'currency' => $amount->currency,
                    'reason' => $reason,
                    'status' => Refund::PENDING,
                    'requested_by' => $requestedBy,
                    'requested_via' => $requestedVia,
                    'idempotency_key' => $idempotencyKey,
                ]);
            });
        } catch (QueryException $exception) {
            if (($exception->errorInfo[0] ?? null) !== '23505') {
                throw $exception;
            }

            // Course sur la clé d'idempotence : la contrainte a tranché, on rend
            // le remboursement déjà enregistré plutôt qu'une erreur.
            $byKey = $this->existing($intent, $idempotencyKey);

            if ($byKey !== null) {
                return $byKey;
            }

            throw $exception;
        }
    }

    /**
     * Ce qui reste remboursable : le brut encaissé, moins ce qui est déjà
     * engagé.
     *
     * Un remboursement `failed` ne compte pas : rien n'est sorti, la somme
     * redevient disponible. Un `pending` compte, lui — l'argent est promis.
     */
    private function refundable(PaymentIntent $intent, string $currency): Money
    {
        $encaisse = (int) PaymentTransaction::query()
            ->where('payment_intent_id', $intent->id)
            ->where('type', PaymentTransaction::CHARGE)
            ->sum('amount');

        $engage = (int) Refund::query()
            ->where('payment_intent_id', $intent->id)
            ->whereIn('status', Refund::HOLDS_FUNDS)
            ->sum('amount');

        return Money::of(max(0, $encaisse - $engage), $currency);
    }

    /**
     * Additionner des XAF et des EUR n'a aucun sens et doit échouer avant toute
     * écriture, pas au moment d'afficher un montant.
     */
    private function guardCurrency(PaymentIntent $intent, Money $amount): void
    {
        if ($amount->currency !== $intent->currency) {
            throw DomainException::unprocessable(
                'CURRENCY_MISMATCH',
                __('payments::messages.currency_mismatch'),
            );
        }
    }

    private function existing(PaymentIntent $intent, ?string $idempotencyKey): ?Refund
    {
        if ($idempotencyKey === null) {
            return null;
        }

        return Refund::query()
            ->where('payment_intent_id', $intent->id)
            ->where('idempotency_key', $idempotencyKey)
            ->first();
    }
}
