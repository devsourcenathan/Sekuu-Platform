<?php

declare(strict_types=1);

namespace Modules\Identity\Application\Products;

use App\Platform\Events\DomainEvent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Applique les droits d'accès décidés par Billing.
 *
 * `organization_products` est un **cache de droits dérivé**, jamais une source
 * de vérité financière. Billing publie un fait ; Identity applique. En cas de
 * désaccord, Billing fait foi.
 *
 * Le consommateur applique un **état cible** — « ces produits, actifs jusqu'à
 * cette date » — et non un delta : le même événement peut être livré plusieurs
 * fois, et un delta appliqué deux fois est un bug silencieux.
 *
 * @see docs/03-services/billing/04-events.md
 * @see docs/03-services/identity/02-data-model.md
 */
final class ApplySubscriptionAccess implements ShouldQueue
{
    public string $queue = 'default';

    private const OPENS_ACCESS = [
        'billing.subscription.activated',
        'billing.subscription.renewed',
        'billing.subscription.changed',
    ];

    private const CLOSES_ACCESS = [
        'billing.subscription.suspended',
        'billing.subscription.expired',
    ];

    public function handle(DomainEvent $event): void
    {
        if ($event->organizationId === null) {
            return;
        }

        if (in_array($event->type, self::OPENS_ACCESS, true)) {
            $this->open($event);

            return;
        }

        if (in_array($event->type, self::CLOSES_ACCESS, true)) {
            $this->close($event);
        }
    }

    private function open(DomainEvent $event): void
    {
        $products = $event->get('products', []);
        $subscriptionId = $event->get('subscription_id');
        $expiresAt = $event->get('current_period_end');

        // Les produits sont **portés par l'événement**, jamais rechargés depuis
        // une table de Billing : Identity ne lit aucune table d'un autre module.
        if (! is_array($products) || $products === []) {
            // `changed` sur une descente différée ne porte pas de produits :
            // rien à appliquer aujourd'hui, l'effet vient au renouvellement.
            return;
        }

        DB::transaction(function () use ($event, $products, $subscriptionId, $expiresAt): void {
            $now = now();

            foreach ($products as $productId) {
                DB::table('organization_products')->updateOrInsert(
                    [
                        'organization_id' => $event->organizationId,
                        'product_id' => $productId,
                    ],
                    [
                        'id' => (string) Str::uuid(),
                        'status' => 'active',
                        'source' => 'subscription',
                        'activated_at' => $now,
                        'expires_at' => $expiresAt,
                        'subscription_id' => $subscriptionId,
                        'updated_at' => $now,
                        'created_at' => $now,
                    ],
                );
            }

            // Les produits retirés du plan sont fermés. Sans cela, une descente
            // en gamme laisserait ouverts des produits qui ne sont plus payés.
            //
            // `source = 'subscription'` uniquement : une activation commerciale
            // accordée par un humain ne se révoque pas au motif qu'aucun
            // abonnement ne la justifie.
            DB::table('organization_products')
                ->where('organization_id', $event->organizationId)
                ->where('source', 'subscription')
                ->whereNotIn('product_id', $products)
                ->update(['status' => 'expired', 'updated_at' => $now]);
        });

        Log::info('Droits d\'accès appliqués depuis Billing.', [
            'organization_id' => $event->organizationId,
            'products' => count($products),
            'event' => $event->type,
        ]);
    }

    private function close(DomainEvent $event): void
    {
        $status = $event->type === 'billing.subscription.expired' ? 'expired' : 'suspended';

        DB::table('organization_products')
            ->where('organization_id', $event->organizationId)
            ->where('source', 'subscription')
            ->update(['status' => $status, 'updated_at' => now()]);
    }
}
