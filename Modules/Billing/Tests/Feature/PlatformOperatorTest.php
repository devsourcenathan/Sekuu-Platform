<?php

declare(strict_types=1);

namespace Modules\Billing\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Billing\Domain\Models\Plan;
use Modules\Billing\Domain\Models\Subscription;
use Modules\Billing\Tests\Concerns\BillsAnOrganization;
use Modules\Identity\Domain\Models\AuditLog;
use Modules\Identity\Domain\Models\PlatformOperator;
use Modules\Identity\Domain\Models\User;
use Tests\TestCase;

/**
 * Administrer la plateforme : qui peut, ce qui est tracé, et quand un
 * changement prend effet.
 *
 * @see docs/04-decisions/adr-0018-platform-operator.md
 * @see docs/04-decisions/adr-0019-granted-limits.md
 */
final class PlatformOperatorTest extends TestCase
{
    use BillsAnOrganization;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useFakeProviders();
        $this->signInAsOwner();
        $this->withToken($this->ownerToken);
    }

    /**
     * Le rôle `owner` est le plus fort qu'une organisation puisse donner. Il ne
     * donne rien ici : personne n'agit au nom de Sekuu par un rôle
     * d'organisation.
     */
    public function test_an_organization_owner_is_not_a_platform_operator(): void
    {
        $this->getJson('/api/v1/platform/plans')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'PLATFORM_ACCESS_DENIED');
    }

    public function test_an_anonymous_call_is_refused_too(): void
    {
        $this->flushHeaders();

        $this->getJson('/api/v1/platform/plans')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'PLATFORM_ACCESS_DENIED');
    }

    /**
     * Des permissions séparées, pas un drapeau : corriger un quota ne donne pas
     * accès aux factures de tous les clients.
     */
    public function test_a_permission_does_not_open_the_others(): void
    {
        $this->makeOperator([PlatformOperator::BILLING]);

        $this->getJson('/api/v1/platform/plans')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'PLATFORM_ACCESS_DENIED');
    }

    /**
     * `platform.operators` existe dans le modèle **pour être refusée** : une
     * permission qui distribue des permissions transformerait le premier compte
     * compromis en un nombre illimité.
     */
    public function test_the_permission_that_grants_permissions_is_inert(): void
    {
        $operator = $this->makeOperator([PlatformOperator::OPERATORS]);

        $this->assertFalse($operator->may(PlatformOperator::OPERATORS));
    }

    /**
     * Une révocation agit tout de suite, sans attendre l'expiration du jeton.
     * C'est pourquoi l'habilitation est relue en base à chaque requête.
     */
    public function test_a_revocation_acts_immediately_on_the_same_token(): void
    {
        $operator = $this->makeOperator([PlatformOperator::PLANS]);

        $this->getJson('/api/v1/platform/plans')->assertOk();

        $operator->forceFill(['revoked_at' => now()])->save();

        $this->getJson('/api/v1/platform/plans')->assertForbidden();
    }

    /**
     * Le cœur de la demande : changer un quota sans déployer.
     */
    public function test_an_operator_changes_a_quota_without_touching_the_code(): void
    {
        $this->makeOperator([PlatformOperator::PLANS]);

        $this->patchJson('/api/v1/platform/plans/business', [
            'limits' => ['members' => 25, 'workspaces' => 5, 'ai_credits_monthly' => 5000],
        ])
            ->assertOk()
            ->assertJsonPath('data.limits.ai_credits_monthly', 5000);

        $this->assertSame(5000, Plan::query()->where('key', 'business')->first()->limits['ai_credits_monthly']);
    }

    /**
     * Une clé inventée serait acceptée en base, lue par personne, et donnerait
     * l'illusion d'une limite en place.
     */
    public function test_an_unknown_limit_key_is_refused(): void
    {
        $this->makeOperator([PlatformOperator::PLANS]);

        $this->patchJson('/api/v1/platform/plans/business', [
            'limits' => ['nombre_de_licornes' => 3],
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'PLAN_LIMIT_UNKNOWN');
    }

    /**
     * L'asymétrie : la plateforme peut être plus généreuse que promis, jamais
     * moins.
     */
    public function test_a_raise_applies_now_and_a_cut_waits_for_renewal(): void
    {
        $this->subscribe('business');
        $this->withToken($this->ownerToken);
        $this->makeOperator([PlatformOperator::PLANS]);

        $subscription = Subscription::query()->firstOrFail();
        $before = $subscription->granted_limits;

        // `workspaces` vaut 25 sur ce plan, `storage_gb` 500. On monte l'un,
        // on baisse l'autre, et on ajoute une clé qui n'existait pas.
        $response = $this->patchJson('/api/v1/platform/plans/business', [
            'limits' => [
                'workspaces' => 100,           // hausse
                'storage_gb' => 10,            // baisse
                'ai_credits_monthly' => 5000,  // apparition = hausse
            ],
        ])->assertOk()->json('data');

        $after = $subscription->fresh()->granted_limits;

        $this->assertSame(100, $after['workspaces']);
        $this->assertSame(5000, $after['ai_credits_monthly']);

        // La baisse n'a pas touché ce que le client a déjà payé.
        $this->assertSame($before['storage_gb'], $after['storage_gb']);

        $this->assertContains('workspaces', $response['applied_now']);
        $this->assertContains('storage_gb', $response['applied_at_renewal']);
    }

    /**
     * `null` vaut illimité, donc supérieur à tout nombre : y revenir depuis
     * `null` est une **baisse**, même vers un grand nombre.
     */
    public function test_leaving_unlimited_is_a_cut_even_towards_a_large_number(): void
    {
        $this->subscribe('business');
        $this->withToken($this->ownerToken);
        $this->makeOperator([PlatformOperator::PLANS]);

        // Sur ce plan, `members` est déjà illimité — le cas se présente sans
        // qu'on ait à le fabriquer. Et `PATCH` fusionnant, envoyer cette seule
        // clé ne touche pas aux autres.
        $subscription = Subscription::query()->firstOrFail();
        $this->assertNull($subscription->granted_limits['members']);

        $this->patchJson('/api/v1/platform/plans/business', ['limits' => ['members' => 10_000]])
            ->assertOk()
            ->assertJsonPath('data.applied_at_renewal', ['members']);

        $this->assertNull($subscription->fresh()->granted_limits['members']);
    }

    /**
     * Consulter la facture d'un client, c'est accéder à une donnée qui ne nous
     * appartient pas. Sans trace, la seule garantie offerte au client est notre
     * parole.
     */
    public function test_even_a_read_is_written_to_the_audit_log(): void
    {
        $this->makeOperator([PlatformOperator::PLANS]);

        $before = AuditLog::query()->where('action', 'platform.get')->count();

        $this->getJson('/api/v1/platform/plans')->assertOk();

        $this->assertSame($before + 1, AuditLog::query()->where('action', 'platform.get')->count());
    }

    /**
     * Et une tentative refusée mérite autant d'être tracée qu'un accès réussi —
     * c'est même la première chose qu'on cherchera le jour d'un incident.
     */
    public function test_a_refused_attempt_is_traced_as_well(): void
    {
        $this->getJson('/api/v1/platform/plans')->assertForbidden();

        $this->assertSame(
            403,
            AuditLog::query()->where('action', 'platform.get')->latest('created_at')->first()->payload['status'],
        );
    }

    /**
     * Consulter les organisations et consulter leurs factures sont deux
     * permissions : constater qu'un client existe n'est pas la même chose que
     * voir ce qu'il paie.
     */
    public function test_seeing_organizations_does_not_open_their_invoices(): void
    {
        $this->subscribe('business');
        $this->withToken($this->ownerToken);
        $this->makeOperator([PlatformOperator::ORGANIZATIONS]);

        $this->getJson('/api/v1/platform/organizations')->assertOk();

        $this->getJson("/api/v1/platform/organizations/{$this->organizationId}/invoices")
            ->assertForbidden()
            ->assertJsonPath('error.code', 'PLATFORM_ACCESS_DENIED');
    }

    /**
     * La question qu'un support se pose vraiment : pourquoi ce client est-il
     * bloqué ? La réponse est ce qui lui a été **promis**, pas ce que le
     * catalogue dit aujourd'hui.
     */
    public function test_an_operator_sees_what_was_granted_not_the_catalogue(): void
    {
        $this->subscribe('business');
        $this->withToken($this->ownerToken);
        $this->makeOperator([PlatformOperator::ORGANIZATIONS, PlatformOperator::PLANS]);

        $this->patchJson('/api/v1/platform/plans/business', ['limits' => ['storage_gb' => 1]])->assertOk();

        $view = $this->getJson("/api/v1/platform/organizations/{$this->organizationId}")
            ->assertOk()
            ->json('data');

        // Le catalogue dit 1, le client garde ce qu'il a payé.
        $this->assertNotSame(1, $view['subscription']['granted_limits']['storage_gb']);
        $this->assertNotNull($view['subscription']['limits_granted_at']);
    }

    /**
     * Un opérateur voit qu'un document existe. Il ne l'ouvre pas — c'est la
     * frontière qui empêche la confidentialité de Storage de n'être qu'une
     * discipline.
     */
    public function test_an_operator_sees_that_a_document_exists_never_its_content(): void
    {
        $this->subscribe('business');
        $this->withToken($this->ownerToken);
        $this->makeOperator([PlatformOperator::BILLING]);

        $invoices = $this->getJson("/api/v1/platform/organizations/{$this->organizationId}/invoices")
            ->assertOk()
            ->json('data');

        $this->assertArrayHasKey('has_pdf', $invoices[0]);
        $this->assertArrayNotHasKey('pdf_file_id', $invoices[0]);
        $this->assertArrayNotHasKey('billing_details', $invoices[0]);
    }

    /**
     * Le rattrapage ne baisse rien par défaut : un outil qui reprendrait
     * silencieusement ce qui a été promis passerait inaperçu.
     */
    public function test_the_catch_up_command_only_raises(): void
    {
        $this->subscribe('business');

        $subscription = Subscription::query()->firstOrFail();
        $subscription->forceFill(['granted_limits' => ['storage_gb' => 9_999]])->save();

        $this->artisan('billing:regrant')->assertSuccessful();

        $this->assertSame(9_999, $subscription->fresh()->granted_limits['storage_gb']);

        // `--force` est la porte délibérée, et elle seule.
        $this->artisan('billing:regrant', ['--force' => true])->assertSuccessful();

        $this->assertNotSame(9_999, $subscription->fresh()->granted_limits['storage_gb']);
    }

    /**
     * @param  list<string>  $permissions
     */
    private function makeOperator(array $permissions): PlatformOperator
    {
        return PlatformOperator::query()->create([
            'user_id' => User::query()->where('email', 'nathan@sekuu.com')->firstOrFail()->id,
            'permissions' => $permissions,
            'granted_at' => now(),
        ]);
    }
}
