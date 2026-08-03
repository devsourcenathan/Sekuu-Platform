<?php

declare(strict_types=1);

namespace Modules\Billing\Infrastructure\Providers;

use Modules\Billing\Domain\Money;
use Modules\Billing\Domain\Msisdn;

/**
 * Demande de débit adressée à un agrégateur.
 *
 * `merchantReference` est écrite en base **avant** cet appel : c'est la seule
 * clé permettant de retrouver une transaction dont on n'a jamais reçu la
 * réponse.
 */
final readonly class ChargeRequest
{
    public function __construct(
        public Money $money,
        public Msisdn $msisdn,
        public string $merchantReference,
        public string $description,
    ) {}
}
