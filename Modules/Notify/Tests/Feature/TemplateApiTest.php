<?php

declare(strict_types=1);

namespace Modules\Notify\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Identity\Application\ApiKeys\IssueApiKey;
use Modules\Identity\Domain\Models\Organization;
use Modules\Notify\Application\Sending\SendNotification;
use Modules\Notify\Application\Sending\SendRequest;
use Modules\Notify\Domain\Models\NotificationTemplate;
use Modules\Notify\Tests\Concerns\UsesApiKey;
use Tests\TestCase;

/**
 * @see docs/03-services/notify/02-data-model.md
 */
final class TemplateApiTest extends TestCase
{
    use RefreshDatabase;
    use UsesApiKey;

    public function test_the_platform_catalogue_is_listed(): void
    {
        $response = $this->withToken($this->issueKey(['notifications.read']))
            ->getJson('/api/v1/templates')
            ->assertOk();

        $platform = collect($response->json('data'))->firstWhere('key', 'password.reset');

        $this->assertSame('platform', $platform['scope']);
        $this->assertFalse($platform['editable']);
        $this->assertEqualsCanonicalizing(['fr', 'en'], $platform['locales']);
    }

    /**
     * Les templates de plateforme sont versionnés avec le code, comme les
     * migrations : les modifier par l'API rendrait le déploiement imprévisible.
     */
    public function test_a_platform_template_cannot_be_modified(): void
    {
        $id = $this->platformTemplate()->id;
        $key = $this->issueKey(['notifications.manage']);

        $this->withToken($key)->patchJson('/api/v1/templates/'.$id, ['status' => 'archived'])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'TEMPLATE_READ_ONLY');

        $this->withToken($key)->deleteJson('/api/v1/templates/'.$id)
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'TEMPLATE_READ_ONLY');
    }

    public function test_an_organisation_can_create_a_variant(): void
    {
        $this->withToken($this->issueKey(['notifications.manage']))
            ->postJson('/api/v1/templates', [
                'key' => 'invitation.sent',
                'channel' => 'email',
                'contents' => [
                    ['locale' => 'fr', 'subject' => 'Rejoignez-nous', 'body' => '<p>Bonjour {{ organization_name }}</p>'],
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('data.scope', 'organization')
            ->assertJsonPath('data.editable', true);
    }

    /**
     * Le point le plus important : sans cette règle, une organisation
     * requalifierait ses invitations en transactionnel et contournerait le
     * désabonnement.
     */
    public function test_a_variant_inherits_the_platform_category(): void
    {
        $this->withToken($this->issueKey(['notifications.manage']))
            ->postJson('/api/v1/templates', [
                'key' => 'invitation.sent',
                'channel' => 'email',
                'category' => 'transactional',
                'contents' => [['locale' => 'fr', 'body' => 'corps']],
            ])
            ->assertCreated()
            // La catégorie demandée est ignorée au profit de celle du
            // catalogue de plateforme.
            ->assertJsonPath('data.category', 'operational');
    }

    public function test_a_new_key_cannot_be_transactional(): void
    {
        $this->withToken($this->issueKey(['notifications.manage']))
            ->postJson('/api/v1/templates', [
                'key' => 'produit.alerte',
                'channel' => 'email',
                'category' => 'transactional',
                'contents' => [['locale' => 'fr', 'body' => 'corps']],
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');
    }

    public function test_a_new_key_can_be_operational(): void
    {
        $this->withToken($this->issueKey(['notifications.manage']))
            ->postJson('/api/v1/templates', [
                'key' => 'produit.alerte',
                'channel' => 'email',
                'category' => 'operational',
                'contents' => [['locale' => 'en', 'subject' => 'Alert', 'body' => 'Something happened']],
            ])
            ->assertCreated()
            ->assertJsonPath('data.category', 'operational');
    }

    /**
     * La variante prend le pas sur le template de plateforme : c'est tout
     * l'objet de la personnalisation.
     */
    public function test_a_variant_takes_precedence_when_sending(): void
    {
        $this->withToken($this->issueKey(['notifications.manage']))
            ->postJson('/api/v1/templates', [
                'key' => 'invitation.sent',
                'channel' => 'email',
                'contents' => [['locale' => 'en', 'subject' => 'Sur mesure', 'body' => 'Variante maison']],
            ])->assertCreated();

        $outcome = $this->app->make(SendNotification::class)->handle(SendRequest::toEmail(
            templateKey: 'invitation.sent',
            email: 'john@gmail.com',
            variables: [
                'organization_name' => 'SOS Clinique',
                'role' => 'member',
                'accept_url' => 'https://app.sekuu.com/i/1',
                'expires_at' => '2026-08-10',
            ],
            organizationId: $this->organizationId,
        ));

        $this->assertSame('Variante maison', $outcome->first()->rendered_body);
    }

    public function test_a_duplicate_variant_is_refused(): void
    {
        $key = $this->issueKey(['notifications.manage']);
        $payload = [
            'key' => 'invitation.sent',
            'channel' => 'email',
            'contents' => [['locale' => 'fr', 'body' => 'corps']],
        ];

        $this->withToken($key)->postJson('/api/v1/templates', $payload)->assertCreated();

        $this->withToken($key)->postJson('/api/v1/templates', $payload)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'DUPLICATE_RESOURCE');
    }

    public function test_updating_a_variant_bumps_its_version(): void
    {
        $id = $this->createVariant();

        $this->withToken($this->issueKey(['notifications.manage']))
            ->patchJson('/api/v1/templates/'.$id, [
                'contents' => [['locale' => 'fr', 'subject' => 'Nouveau', 'body' => 'Nouveau corps']],
            ])
            ->assertOk()
            // Sans numéro de version, un message rendu avec l'ancienne version
            // serait indistinguable.
            ->assertJsonPath('data.version', 2);
    }

    public function test_deleting_a_variant_archives_it(): void
    {
        $id = $this->createVariant();

        $this->withToken($this->issueKey(['notifications.manage']))
            ->deleteJson('/api/v1/templates/'.$id)
            ->assertNoContent();

        // Archivé, pas supprimé : des messages déjà envoyés y renvoient.
        $this->assertSame('archived', NotificationTemplate::query()->findOrFail($id)->status);
    }

    /**
     * Seul moyen honnête de vérifier un template avant de l'exposer à de vrais
     * destinataires.
     */
    public function test_a_template_can_be_previewed_without_sending(): void
    {
        $id = $this->createVariant();

        $this->withToken($this->issueKey(['notifications.manage']))
            ->postJson('/api/v1/templates/'.$id.'/preview', [
                'locale' => 'fr',
                'variables' => ['organization_name' => 'SOS Clinique'],
            ])
            ->assertOk()
            ->assertJsonPath('data.body', 'Bienvenue chez SOS Clinique');

        // Rien n'est envoyé, rien n'est enregistré.
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_a_preview_reports_missing_variables(): void
    {
        $id = $this->platformTemplate()->id;

        $this->withToken($this->issueKey(['notifications.manage']))
            ->postJson('/api/v1/templates/'.$id.'/preview', ['variables' => []])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'TEMPLATE_VARIABLE_MISSING');
    }

    /**
     * La variante d'une autre organisation est indiscernable d'un template
     * inexistant.
     */
    public function test_another_organisations_variant_is_invisible(): void
    {
        $id = $this->createVariant();

        // Seconde organisation, avec sa propre clé.
        $other = Organization::create(['name' => 'Autre entreprise', 'slug' => 'autre-entreprise']);

        $foreignKey = $this->app->make(IssueApiKey::class)->handle(
            organizationId: $other->id,
            name: 'Autre',
            scopes: ['notifications.read', 'notifications.manage'],
            creator: $this->apiKeyOwner,
        )->plainKey;

        // La liste ne montre que le catalogue de plateforme.
        $scopes = collect(
            $this->withToken($foreignKey)->getJson('/api/v1/templates')->assertOk()->json('data')
        )->pluck('scope')->unique()->values()->all();

        $this->assertSame(['platform'], $scopes);

        // Et l'accès direct est indiscernable d'un template inexistant.
        $this->withToken($foreignKey)->getJson('/api/v1/templates/'.$id)
            ->assertNotFound()
            ->assertJsonPath('error.code', 'TEMPLATE_NOT_FOUND');

        $this->withToken($foreignKey)->deleteJson('/api/v1/templates/'.$id)
            ->assertNotFound();
    }

    public function test_managing_templates_requires_the_manage_scope(): void
    {
        $this->withToken($this->issueKey(['notifications.read']))
            ->postJson('/api/v1/templates', [
                'key' => 'produit.alerte',
                'channel' => 'email',
                'category' => 'operational',
                'contents' => [['locale' => 'fr', 'body' => 'corps']],
            ])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'INSUFFICIENT_PERMISSIONS');
    }

    // ------------------------------------------------------------ fixtures --

    private function platformTemplate(): NotificationTemplate
    {
        return NotificationTemplate::query()
            ->whereNull('organization_id')
            ->where('key', 'password.reset')
            ->where('channel', 'email')
            ->firstOrFail();
    }

    private function createVariant(): string
    {
        return $this->withToken($this->issueKey(['notifications.manage']))
            ->postJson('/api/v1/templates', [
                'key' => 'invitation.sent',
                'channel' => 'email',
                'variables' => [['name' => 'organization_name', 'required' => true]],
                'contents' => [['locale' => 'fr', 'subject' => 'Bienvenue', 'body' => 'Bienvenue chez {{ organization_name }}']],
            ])
            ->assertCreated()
            ->json('data.id');
    }
}
