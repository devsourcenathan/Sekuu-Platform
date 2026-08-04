<?php

declare(strict_types=1);

namespace Modules\Payments\Application\External;

use Modules\Payments\Domain\Models\ExternalCharge;
use Modules\Payments\Domain\Models\PaymentIntent;

/**
 * Ce qu'une déclaration de prix produit : la charge, et l'intention qu'elle a
 * déclenchée.
 *
 * Les deux, parce que la charge est ce que le produit interrogera ensuite, et
 * que l'intention porte l'issue immédiate — `pending` si l'invite est partie,
 * `failed` si personne n'a accepté.
 *
 * Le lien n'est écrit en base qu'au règlement : une intention en cours n'a pas
 * encore réglé sa charge, et remplir `payment_intent_id` par avance ferait
 * mentir la table sur ce qui a été constaté.
 */
final readonly class DeclaredCharge
{
    public function __construct(
        public ExternalCharge $charge,
        public PaymentIntent $intent,
    ) {}
}
