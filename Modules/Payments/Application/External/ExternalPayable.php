<?php

declare(strict_types=1);

namespace Modules\Payments\Application\External;

use App\Platform\Contracts\PayableQuote;
use App\Platform\Contracts\PayableRef;
use App\Platform\Contracts\PayableSource;
use App\Platform\Contracts\PayerContext;
use App\Platform\Contracts\PaymentSettlement;
use Modules\Payments\Domain\Models\ExternalCharge;

/**
 * Le propriétaire d'un objet payable qui vit **hors de cette base de code**.
 *
 * ## Ce qui change, et ce qui ne change pas
 *
 * Un module du monolithe implémente `PayableSource` et répond de mémoire :
 * il charge son objet et lit son prix. Un service externe ne peut pas
 * implémenter d'interface PHP.
 *
 * La tentation serait alors de passer le montant à `InitiatePayment`. C'est
 * précisément la signature que `PayableQuote` existe pour ne jamais offrir : le
 * premier appelant écrirait `$request->integer('amount')`, et régler 49 663 XAF
 * avec 100 XAF redeviendrait possible.
 *
 * D'où ce détour. Le produit **déclare** son prix par une requête authentifiée,
 * la plateforme l'écrit dans `external_charges`, et `quote()` le relit en base
 * — exactement comme Billing relit sa facture. Le mécanisme est inchangé ; ce
 * qui change est qui a rempli la ligne.
 *
 * ## L'invariant ne repose donc pas sur la confiance
 *
 * Il repose sur deux bornes, posées avant que cette classe ne soit atteinte :
 * la clé d'API porte la liste des `subject_type` qu'elle peut faire payer, et
 * `billing.invoice` n'y figure jamais.
 *
 * @see docs/03-services/payments/07-external-api.md
 * @see docs/04-decisions/adr-0010-external-payment-api.md
 */
final class ExternalPayable implements PayableSource
{
    public function __construct(private readonly NotifyExternalProduct $notify) {}

    public function quote(PayableRef $ref, PayerContext $payer): PayableQuote
    {
        $charge = $this->pendingCharge($ref);

        if ($charge === null) {
            return PayableQuote::refused(
                'CHARGE_NOT_FOUND',
                __('payments::messages.external_charge_not_found'),
            );
        }

        // Le payeur déclaré à la création est le seul qui puisse régler.
        //
        // **Le même refus que « inexistante »**, délibérément : deux messages
        // distincts transformeraient l'endpoint en oracle permettant d'énumérer
        // les charges d'un autre produit.
        if ($charge->payer_type !== $payer->type || $charge->payer_id !== $payer->id) {
            return PayableQuote::refused(
                'CHARGE_NOT_FOUND',
                __('payments::messages.external_charge_not_found'),
            );
        }

        return PayableQuote::due(
            $charge->money(),
            $charge->description,

            // `null` : la plateforme encaisse. Reverser à un tiers suppose un
            // compte de destination, un type `payout` au registre et un état de
            // reversement — rien de tout cela n'existe.
            payeeOrganizationId: null,
        );
    }

    /**
     * Appelée **dans la transaction d'encaissement**, comme pour un module
     * interne. Elle n'écrit donc que des lignes : la livraison au produit est
     * enfilée, jamais tentée ici.
     */
    public function settled(PaymentSettlement $settlement): void
    {
        $charge = $this->lockedCharge($settlement);

        if ($charge === null || $charge->isSettled()) {
            // Idempotente : un callback puis un sondage peuvent régler le même
            // paiement deux fois.
            return;
        }

        $charge->forceFill([
            'status' => ExternalCharge::PAID,
            'payment_intent_id' => $settlement->paymentIntentId,
            'settled_at' => now(),
        ])->save();

        $this->notify->handle($charge, NotifyExternalProduct::SUCCEEDED);
    }

    public function failed(PaymentSettlement $settlement): void
    {
        $charge = $this->lockedCharge($settlement);

        if ($charge === null || $charge->isSettled()) {
            return;
        }

        $charge->forceFill([
            'status' => ExternalCharge::FAILED,
            'payment_intent_id' => $settlement->paymentIntentId,
            'settled_at' => now(),
        ])->save();

        // Le produit prévient son client **dans ses propres termes**. La
        // plateforme ne le peut pas : la résolution du contact passe par
        // Identity, qui ne connaît pas ce payeur.
        $this->notify->handle($charge, NotifyExternalProduct::FAILED);
    }

    private function pendingCharge(PayableRef $ref): ?ExternalCharge
    {
        return ExternalCharge::query()
            ->where('subject_type', $ref->type)
            ->where('subject_id', $ref->id)
            ->where('status', ExternalCharge::PENDING)
            ->first();
    }

    private function lockedCharge(PaymentSettlement $settlement): ?ExternalCharge
    {
        return ExternalCharge::query()
            ->where('subject_type', $settlement->subject->type)
            ->where('subject_id', $settlement->subject->id)
            ->where('status', ExternalCharge::PENDING)
            ->lockForUpdate()
            ->first();
    }
}
