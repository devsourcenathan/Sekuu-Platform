<?php

declare(strict_types=1);

namespace Modules\Billing\Tests\Feature;

use App\Platform\Contracts\BillingContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Billing\Domain\Models\Plan;
use Modules\Billing\Domain\Models\Subscription;
use Modules\Billing\Tests\Concerns\BillsAnOrganization;
use Modules\Payments\Infrastructure\Providers\ChargeOutcome;
use Modules\Payments\Tests\Support\FakeProvider;
use Tests\TestCase;

/**
 * Billing publie les limites ; chaque module les fait respecter.
 *
 * Ces tests portent sur la lecture — le contrat — et sur son application côté
 * Identity, qui compte les sièges et les workspaces.
 *
 * @see docs/03-services/billing/01-overview.md
 */
final class PlanQuotaTest extends TestCase
{
    use BillsAnOrganization;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useFakeProviders();
        $this->signInAsOwner();
    }

    /**
     * Trois états, et non deux. Confondre « illimité » et « non couvert »
     * ferait lire l'un pour l'autre — et le mauvais sens bloquerait un client
     * qui a payé pour ne pas l'être.
     */
    public function test_the_three_states_of_a_limit_are_distinguished(): void
    {
        $this->activate('business');

        $billing = $this->app->make(BillingContract::class);

        // Plafonnée.
        $this->assertSame(25, $billing->limit($this->organizationId, 'workspaces')->value);

        // Illimitée : le plan Business ne borne pas les membres.
        $this->assertTrue($billing->limit($this->organizationId, 'members')->isUnlimited());

        // Non couverte : la clé n'existe pas dans ce plan.
        $this->assertFalse($billing->limit($this->organizationId, 'ai_credits_monthly')->covered);
    }

    /**
     * Sans abonnement, il n'y a rien à plafonner : l'accès lui-même est fermé,
     * et c'est Identity qui l'applique. Bloquer ici dupliquerait ce rôle — et
     * fermerait toute organisation créée avant qu'un abonnement n'existe.
     */
    public function test_an_organisation_without_a_subscription_is_not_blocked(): void
    {
        $this->assertFalse(
            $this->app->make(BillingContract::class)->limit($this->organizationId, 'workspaces')->covered
        );

        $this->withToken($this->ownerToken)
            ->postJson('/api/v1/workspaces', ['name' => 'Cabinet A'])
            ->assertCreated();
    }

    public function test_creating_a_workspace_beyond_the_plan_is_refused(): void
    {
        // Starter n'autorise qu'un seul workspace.
        $this->activate('starter');

        $this->withToken($this->ownerToken)
            ->postJson('/api/v1/workspaces', ['name' => 'Cabinet A'])
            ->assertCreated();

        $this->flushHeaders();

        $response = $this->withToken($this->ownerToken)
            ->postJson('/api/v1/workspaces', ['name' => 'Cabinet B'])
            ->assertStatus(429)
            ->assertJsonPath('error.code', 'QUOTA_EXCEEDED');

        // Le détail dit où l'on en est, pas seulement qu'on est bloqué.
        $this->assertSame(
            ['limit' => 'workspaces', 'current' => 1, 'allowed' => 1],
            $response->json('error.details'),
        );
    }

    /**
     * Le siège est réservé dès l'invitation. Ne compter que les membres
     * laisserait envoyer cent invitations sur un plan de trois sièges, et le
     * dépassement ne serait constaté qu'à l'acceptation — une fois la promesse
     * faite à l'invité.
     */
    public function test_pending_invitations_consume_seats(): void
    {
        $this->activate('starter');

        $roleId = \DB::table('global_roles')->where('slug', 'member')->value('id');

        // Starter : 3 sièges. Le propriétaire en occupe déjà un.
        foreach (['a@sekuu.com', 'b@sekuu.com'] as $email) {
            $this->withToken($this->ownerToken)
                ->postJson('/api/v1/organizations/'.$this->organizationId.'/invitations', [
                    'email' => $email,
                    'global_role_id' => $roleId,
                ])
                ->assertCreated();

            $this->flushHeaders();
        }

        $this->withToken($this->ownerToken)
            ->postJson('/api/v1/organizations/'.$this->organizationId.'/invitations', [
                'email' => 'c@sekuu.com',
                'global_role_id' => $roleId,
            ])
            ->assertStatus(429)
            ->assertJsonPath('error.code', 'QUOTA_EXCEEDED');
    }

    /**
     * Un plan illimité ne borne rien — mais reste soumis au plafond de dépense,
     * qui est un garde-fou d'une autre nature.
     */
    public function test_an_unlimited_plan_does_not_block(): void
    {
        $this->activate('business');

        foreach (['a@sekuu.com', 'b@sekuu.com', 'c@sekuu.com', 'd@sekuu.com'] as $email) {
            $this->withToken($this->ownerToken)
                ->postJson('/api/v1/organizations/'.$this->organizationId.'/invitations', [
                    'email' => $email,
                    'global_role_id' => \DB::table('global_roles')->where('slug', 'member')->value('id'),
                ])
                ->assertCreated();

            $this->flushHeaders();
        }

        $this->assertTrue(true);
    }

    private function activate(string $planKey): Subscription
    {
        FakeProvider::willReturn('primary', ChargeOutcome::succeeded('ref-1', gross: 999_999));

        $invoice = $this->subscribe($planKey);

        if ($invoice !== null) {
            $this->payInvoice($invoice, '+237650000000');
        }

        $subscription = Subscription::query()->firstOrFail();

        // L'essai suffit à ouvrir l'accès, mais le quota se lit sur le plan.
        $this->assertNotNull(Plan::query()->where('key', $planKey)->first());

        return $subscription;
    }
}
