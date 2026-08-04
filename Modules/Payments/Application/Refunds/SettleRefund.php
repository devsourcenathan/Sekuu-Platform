<?php

declare(strict_types=1);

namespace Modules\Payments\Application\Refunds;

use App\Platform\Contracts\PayableRef;
use App\Platform\Contracts\RefundableSource;
use App\Platform\Contracts\RefundSettlement;
use App\Platform\Events\PublishesDomainEvents;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Payments\Application\Payments\PayableRegistry;
use Modules\Payments\Domain\Models\PaymentTransaction;
use Modules\Payments\Domain\Models\Refund;

/**
 * Constater qu'un remboursement a réellement eu lieu.
 *
 * Le point d'entrée unique par lequel passent le décaissement manuel et, le
 * jour où un adaptateur existera, celui d'un agrégateur. Un seul endroit écrit
 * au registre — sans quoi le même remboursement serait constaté deux fois selon
 * qui arrive le premier.
 *
 * @see docs/03-services/payments/08-refunds.md
 */
final class SettleRefund
{
    use PublishesDomainEvents;

    public function __construct(private readonly PayableRegistry $payables) {}

    /**
     * L'argent est sorti.
     *
     * `$provider` à `null` signifie un décaissement **manuel**, constaté après
     * coup. Ce n'est pas une anomalie : c'est le cas nominal tant qu'aucun
     * adaptateur de décaissement n'existe.
     */
    public function succeeded(Refund $refund, ?string $provider = null, ?string $providerRef = null): Refund
    {
        return DB::transaction(function () use ($refund, $provider, $providerRef): Refund {
            $locked = Refund::query()->lockForUpdate()->find($refund->id) ?? $refund;

            // Idempotente : un décaissement peut être constaté deux fois, par un
            // opérateur puis par un rapprochement. Écrire deux lignes `refund`
            // laisserait croire que l'argent est sorti deux fois.
            if ($locked->status === Refund::SUCCEEDED) {
                return $locked;
            }

            if ($locked->isSettled()) {
                // `failed` ou `cancelled` : la somme est retournée au
                // disponible, et la reprendre demande une nouvelle décision.
                return $locked;
            }

            $locked->forceFill([
                'status' => Refund::SUCCEEDED,
                'provider' => $provider,
                'provider_ref' => $providerRef,
                'failure_code' => null,
                'failure_reason' => null,
                'settled_at' => now(),
            ])->save();

            $this->record($locked);
            $this->notifyOwner($locked, succeeded: true);

            $this->publish('payments.refund.succeeded', [
                'refund_id' => $locked->id,
                'payment_intent_id' => $locked->payment_intent_id,
                'subject_type' => $locked->subject_type,
                'subject_id' => $locked->subject_id,
                'amount' => $locked->amount,
                'currency' => $locked->currency,
            ], $locked->intent?->contextOrganizationId());

            return $locked;
        });
    }

    /**
     * Le décaissement a échoué.
     *
     * La somme **redevient remboursable** : rien n'est sorti. Une nouvelle
     * décision est nécessaire pour réessayer — jamais un réessai automatique,
     * pour la même raison qu'à l'encaissement : on ignore parfois si l'argent
     * est parti, et l'incertitude ne doit pas produire un second transfert.
     */
    public function failed(Refund $refund, string $code, string $reason): Refund
    {
        return DB::transaction(function () use ($refund, $code, $reason): Refund {
            $locked = Refund::query()->lockForUpdate()->find($refund->id) ?? $refund;

            if ($locked->isSettled()) {
                return $locked;
            }

            $locked->forceFill([
                'status' => Refund::FAILED,
                'failure_code' => $code,
                'failure_reason' => $reason,
                'settled_at' => now(),
            ])->save();

            $this->notifyOwner($locked, succeeded: false);

            return $locked;
        });
    }

    /**
     * Abandonner avant tout décaissement.
     *
     * Distinct de `failed` : personne n'a essayé. La distinction compte pour
     * qui relira le registre — un échec technique et un renoncement ne
     * s'expliquent pas de la même façon.
     */
    public function cancelled(Refund $refund, string $reason): Refund
    {
        return DB::transaction(function () use ($refund, $reason): Refund {
            $locked = Refund::query()->lockForUpdate()->find($refund->id) ?? $refund;

            if ($locked->isSettled() || $locked->status === Refund::PROCESSING) {
                return $locked;
            }

            $locked->forceFill([
                'status' => Refund::CANCELLED,
                'failure_reason' => $reason,
                'settled_at' => now(),
            ])->save();

            return $locked;
        });
    }

    /**
     * La ligne de registre, écrite **une seule fois**, au décaissement constaté.
     *
     * Négative : le registre de caisse enregistre des mouvements signés, et un
     * remboursement fait sortir de l'argent. Le brut encaissé n'est pas modifié
     * — corriger une écriture en la réécrivant effacerait la trace de ce qui
     * s'est réellement passé.
     */
    private function record(Refund $refund): void
    {
        $intent = $refund->intent;

        PaymentTransaction::create([
            'payment_intent_id' => $refund->payment_intent_id,
            'payment_attempt_id' => null,
            'subject_type' => $refund->subject_type,
            'subject_id' => $refund->subject_id,
            'payee_organization_id' => $intent?->payee_organization_id,
            'type' => PaymentTransaction::REFUND,
            'amount' => -$refund->amount,
            'currency' => $refund->currency,
            'occurred_at' => now(),
            'description' => $refund->reason,
            'metadata' => [
                'refund_id' => $refund->id,
                'provider' => $refund->provider,
                'provider_ref' => $refund->provider_ref,
            ],
        ]);
    }

    /**
     * Remise de l'issue au propriétaire, **dans la transaction**.
     *
     * Même raison que pour l'encaissement : confier ce moment à une file
     * créerait une fenêtre où l'argent est rendu et l'accès toujours ouvert,
     * qu'un consommateur en échec définitif rendrait permanente.
     */
    private function notifyOwner(Refund $refund, bool $succeeded): void
    {
        $source = $this->payables->knows($refund->subject_type)
            ? $this->payables->for($refund->subject_type)
            : null;

        if (! $source instanceof RefundableSource) {
            // Le propriétaire a cessé de porter le contrat entre la décision et
            // le décaissement. L'argent est sorti sans que personne ne le sache
            // côté produit : cela doit se voir.
            Log::error('Remboursement constaté sans propriétaire capable de le recevoir.', [
                'refund_id' => $refund->id,
                'subject_type' => $refund->subject_type,
            ]);

            return;
        }

        $source->refunded(new RefundSettlement(
            refundId: $refund->id,
            paymentIntentId: $refund->payment_intent_id,
            subject: new PayableRef($refund->subject_type, $refund->subject_id),
            amount: $refund->money(),
            succeeded: $succeeded,
            provider: $refund->provider,
            providerRef: $refund->provider_ref,
            failureCode: $refund->failure_code,
            failureReason: $refund->failure_reason,
        ));
    }
}
