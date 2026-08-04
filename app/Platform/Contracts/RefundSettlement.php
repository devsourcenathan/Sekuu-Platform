<?php

declare(strict_types=1);

namespace App\Platform\Contracts;

use App\Platform\Support\Money;

/**
 * Ce qu'on rapporte au propriétaire d'un objet une fois le remboursement
 * tranché.
 *
 * `provider` peut être `null` : le décaissement a alors été fait **à la main**,
 * hors plateforme, et constaté après coup. C'est le cas nominal tant qu'aucun
 * adaptateur de décaissement n'existe, et il ne doit pas se présenter comme une
 * anomalie.
 */
final readonly class RefundSettlement
{
    public function __construct(
        public string $refundId,
        public string $paymentIntentId,
        public PayableRef $subject,
        public Money $amount,
        public bool $succeeded,
        public ?string $provider = null,
        public ?string $providerRef = null,
        public ?string $failureCode = null,
        public ?string $failureReason = null,
    ) {}
}
