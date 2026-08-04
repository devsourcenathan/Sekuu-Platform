<?php

declare(strict_types=1);

namespace Modules\Billing\Tests\Concerns;

use App\Platform\Contracts\PayableRef;
use App\Platform\Contracts\PayerContext;
use Modules\Billing\Application\Invoicing\InvoicePayable;
use Modules\Billing\Domain\Models\Invoice;
use Modules\Billing\Domain\Models\Subscription;
use Modules\Billing\Domain\SubscriptionStatus;
use Modules\Payments\Application\Payments\InitiatePayment;
use Modules\Payments\Domain\Models\PaymentIntent;
use Modules\Payments\Infrastructure\Providers\ProviderRegistry;
use Modules\Payments\Tests\Support\FakeProvider;
use Modules\Payments\Tests\Support\PrimaryProvider;
use Modules\Payments\Tests\Support\SecondaryProvider;
use Tests\Concerns\SignsInAsOwner;

trait BillsAnOrganization
{
    use SignsInAsOwner;

    /**
     * Deux agrégateurs factices, dont on contrôle exactement l'issue.
     */
    protected function useFakeProviders(): void
    {
        FakeProvider::reset();

        config()->set('payments.providers', [PrimaryProvider::class, SecondaryProvider::class]);

        $this->app->forgetInstance(ProviderRegistry::class);

        $this->app->singleton(ProviderRegistry::class, function ($app): ProviderRegistry {
            $registry = new ProviderRegistry($app);

            foreach ((array) config('payments.providers', []) as $provider) {
                $registry->register($provider);
            }

            return $registry;
        });
    }

    /**
     * Souscrit et renvoie la facture émise.
     *
     * `null` sur un plan avec essai : il n'y a rien à payer, et c'est tout
     * l'objet de l'essai.
     */
    protected function subscribe(string $planKey = 'business'): ?Invoice
    {
        $response = $this->withToken($this->ownerToken)
            ->postJson('/api/v1/subscription', ['plan_key' => $planKey])
            ->assertCreated();

        $this->flushHeaders();

        $invoiceId = $response->json('data.invoice.id');

        return $invoiceId === null ? null : Invoice::query()->findOrFail($invoiceId);
    }

    /**
     * Règle une facture.
     *
     * Existe pour que les tests appellent le paiement en une ligne : la couche
     * de paiement ne connaît plus la facture, et étaler la construction du
     * `PayableRef` dans vingt tests noierait leurs assertions.
     */
    protected function payInvoice(Invoice $invoice, string $msisdn = '+237650000000'): PaymentIntent
    {
        return app(InitiatePayment::class)->handle(
            subject: new PayableRef(InvoicePayable::TYPE, $invoice->id),
            payer: PayerContext::organization($invoice->organization_id),
            rawMsisdn: $msisdn,
        );
    }

    /**
     * Recule une période entière pour la faire échoir, sans violer la
     * contrainte `current_period_end > current_period_start`.
     */
    protected function expirePeriod(Subscription $subscription): void
    {
        $subscription->forceFill([
            'status' => SubscriptionStatus::Active,
            'current_period_start' => now()->subMonth()->subDay(),
            'current_period_end' => now()->subDay(),
        ])->save();
    }
}
