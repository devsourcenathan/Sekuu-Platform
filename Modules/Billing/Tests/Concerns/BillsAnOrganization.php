<?php

declare(strict_types=1);

namespace Modules\Billing\Tests\Concerns;

use Modules\Billing\Domain\Models\Invoice;
use Modules\Billing\Domain\Models\Subscription;
use Modules\Billing\Domain\SubscriptionStatus;
use Modules\Billing\Infrastructure\Providers\ProviderRegistry;
use Modules\Billing\Tests\Support\FakeProvider;
use Modules\Billing\Tests\Support\PrimaryProvider;
use Modules\Billing\Tests\Support\SecondaryProvider;

trait BillsAnOrganization
{
    protected string $ownerToken;

    protected string $organizationId;

    /**
     * Inscription, création d'organisation, puis bascule du token dessus.
     *
     * Passe par l'API plutôt que par des factories : l'abonnement dépend de
     * rôles et d'un claim d'organisation dans le token, et les fabriquer à la
     * main laisserait le test vert sur un chemin que personne n'emprunte.
     */
    protected function signInAsOwner(string $email = 'nathan@sekuu.com'): void
    {
        $token = $this->postJson('/api/v1/auth/register', [
            'first_name' => 'Nathan',
            'last_name' => 'Tchinda',
            'email' => $email,
            'password' => 'un-mot-de-passe-long',
        ])->assertCreated()->json('data.access_token');

        $organization = $this->withToken($token)
            ->postJson('/api/v1/organizations', ['name' => 'SOS Clinique'])
            ->assertCreated()
            ->json('data');

        $this->organizationId = $organization['id'];

        $this->flushHeaders();

        $this->ownerToken = $this->withToken($token)
            ->postJson('/api/v1/auth/switch-organization', ['organization_id' => $this->organizationId])
            ->assertOk()
            ->json('data.access_token');

        $this->flushHeaders();
    }

    /**
     * Deux agrégateurs factices, dont on contrôle exactement l'issue.
     */
    protected function useFakeProviders(): void
    {
        FakeProvider::reset();

        config()->set('billing.providers', [PrimaryProvider::class, SecondaryProvider::class]);

        $this->app->forgetInstance(ProviderRegistry::class);

        $this->app->singleton(ProviderRegistry::class, function ($app): ProviderRegistry {
            $registry = new ProviderRegistry($app);

            foreach ((array) config('billing.providers', []) as $provider) {
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
