<?php

declare(strict_types=1);

namespace Modules\Billing\Infrastructure\Console;

use Illuminate\Console\Command;
use Modules\Billing\Application\Subscriptions\AdvanceLifecycle;

/**
 * Avancement quotidien : grâce, suspension, expiration, rappels d'échéance.
 *
 * Idempotente : la relancer deux fois le même jour ne raccourcit pas une grâce
 * de deux jours.
 */
final class AdvanceLifecycleCommand extends Command
{
    protected $signature = 'billing:advance';

    protected $description = 'Fait avancer le cycle de vie des abonnements et publie les rappels d\'échéance.';

    public function handle(AdvanceLifecycle $advance): int
    {
        $result = $advance->handle();

        $this->info(sprintf(
            '%d en grâce, %d suspendus, %d expirés, %d rappels.',
            $result['grace'],
            $result['suspended'],
            $result['expired'],
            $result['reminded'],
        ));

        return self::SUCCESS;
    }
}
