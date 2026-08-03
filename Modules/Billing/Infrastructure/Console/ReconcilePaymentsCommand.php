<?php

declare(strict_types=1);

namespace Modules\Billing\Infrastructure\Console;

use Illuminate\Console\Command;
use Modules\Billing\Application\Payments\ReconcilePayments;

/**
 * Sondage des paiements en attente.
 *
 * Ce n'est pas un filet de sécurité optionnel : chez un agrégateur qui
 * déconseille le sondage, un callback perdu produit exactement la défaillance
 * que ce module doit rendre impossible — un client débité sans accès.
 */
final class ReconcilePaymentsCommand extends Command
{
    protected $signature = 'billing:reconcile';

    protected $description = 'Interroge les agrégateurs pour tout paiement en attente.';

    public function handle(ReconcilePayments $reconcile): int
    {
        $result = $reconcile->handle();

        $this->info(sprintf(
            '%d sondés, %d réglés, %d sans issue connue.',
            $result['polled'],
            $result['settled'],
            $result['unresolved'],
        ));

        return self::SUCCESS;
    }
}
