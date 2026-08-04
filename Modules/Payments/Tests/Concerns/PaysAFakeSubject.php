<?php

declare(strict_types=1);

namespace Modules\Payments\Tests\Concerns;

use App\Platform\Contracts\PayableRef;
use App\Platform\Contracts\PayerContext;
use Illuminate\Support\Str;
use Modules\Payments\Application\Payments\InitiatePayment;
use Modules\Payments\Application\Payments\PayableRegistry;
use Modules\Payments\Domain\Models\PaymentIntent;
use Modules\Payments\Infrastructure\Providers\ProviderRegistry;
use Modules\Payments\Tests\Support\FakePayable;
use Modules\Payments\Tests\Support\FakeProvider;
use Modules\Payments\Tests\Support\PrimaryProvider;
use Modules\Payments\Tests\Support\SecondaryProvider;

/**
 * De quoi éprouver l'encaissement **sans facture ni abonnement**.
 *
 * Ces tests vivaient auparavant dans Billing et payaient de vraies factures.
 * C'était commode et trompeur : la facture n'était qu'un véhicule, et sa
 * présence laissait croire que la règle de bascule dépendait de la facturation.
 * Elle n'en dépend pas — et un test qui importe Billing ne peut pas le démontrer.
 *
 * Un test d'architecture interdit d'ailleurs à tout fichier de `Modules/Payments`
 * de nommer Billing, code de test compris.
 */
trait PaysAFakeSubject
{
    /** Le payeur est une **personne**, pas une organisation Sekuu. */
    protected string $payer;

    /**
     * Deux agrégateurs factices et un objet payable factice.
     */
    protected function useFakePayments(): void
    {
        FakeProvider::reset();
        FakePayable::reset();

        $this->payer = (string) Str::uuid();

        $this->useProviders([PrimaryProvider::class, SecondaryProvider::class]);
        $this->useFakePayable();
    }

    /**
     * Substitue la chaîne d'agrégateurs, factices ou réels.
     *
     * @param  list<class-string>  $providers
     */
    protected function useProviders(array $providers): void
    {
        config()->set('payments.providers', $providers);

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
     * Un seul type payable enregistré, et ce n'est pas une facture.
     */
    protected function useFakePayable(): void
    {
        config()->set('payments.payables', [FakePayable::TYPE => FakePayable::class]);

        $this->app->forgetInstance(PayableRegistry::class);

        $this->app->singleton(PayableRegistry::class, function ($app): PayableRegistry {
            $registry = new PayableRegistry($app);

            foreach ((array) config('payments.payables', []) as $type => $source) {
                $registry->register($type, $source);
            }

            return $registry;
        });
    }

    /**
     * Règle un objet quelconque. `null` en tire un au hasard.
     */
    protected function pay(?string $subject = null, string $msisdn = '+237650000000'): PaymentIntent
    {
        return $this->app->make(InitiatePayment::class)->handle(
            subject: new PayableRef(FakePayable::TYPE, $subject ?? (string) Str::uuid()),
            payer: PayerContext::user($this->payer),
            rawMsisdn: $msisdn,
        )->fresh();
    }
}
