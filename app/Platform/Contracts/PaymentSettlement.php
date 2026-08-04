<?php

declare(strict_types=1);

namespace App\Platform\Contracts;

use App\Platform\Support\Money;

/**
 * Ce qu'on rapporte au propriétaire d'un objet une fois le paiement tranché.
 *
 * Le montant est celui de l'**intention**, pas celui rapporté par l'agrégateur :
 * ce dernier est un constat, jamais une autorité. Chez un agrégateur qui
 * authentifie ses callbacks par un secret partagé plutôt que par une signature,
 * croire le montant reçu serait une faille.
 */
final readonly class PaymentSettlement
{
    public function __construct(
        public string $paymentIntentId,
        public PayableRef $subject,
        public PayerContext $payer,
        public Money $amount,
        public string $provider,
        public ?string $failureCode = null,
        public ?string $failureReason = null,
    ) {}
}
