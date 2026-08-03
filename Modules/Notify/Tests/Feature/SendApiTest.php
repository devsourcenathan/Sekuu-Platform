<?php

declare(strict_types=1);

namespace Modules\Notify\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Modules\Identity\Application\ApiKeys\IssueApiKey;
use Modules\Identity\Domain\Models\ApiKey;
use Modules\Identity\Domain\Models\GlobalRole;
use Modules\Identity\Domain\Models\Membership;
use Modules\Identity\Domain\Models\Organization;
use Modules\Identity\Domain\Models\User;
use Modules\Notify\Domain\Models\Notification;
use Modules\Notify\Domain\Models\Suppression;
use Tests\TestCase;

/**
 * @see docs/03-services/notify/03-api.md
 */
final class SendApiTest extends TestCase
{
    use RefreshDatabase;

    private string $apiKey;

    private string $organizationId;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::create([
            'first_name' => 'Nathan',
            'last_name' => 'Tchinda',
            'email' => 'nathan@sekuu.com',
        ]);

        $organization = Organization::create(['name' => 'SOS Clinique', 'slug' => 'sos-clinique']);
        $this->organizationId = $organization->id;

        $membership = Membership::create([
            'user_id' => $this->user->id,
            'organization_id' => $organization->id,
            'status' => 'active',
            'joined_at' => now(),
        ]);
        $membership->roles()->attach(GlobalRole::query()->where('slug', 'owner')->firstOrFail()->id);

        $this->apiKey = $this->app->make(IssueApiKey::class)->handle(
            organizationId: $organization->id,
            name: 'Tests',
            scopes: ['notifications.send'],
            creator: $this->user,
        )->plainKey;
    }

    /**
     * Un utilisateur connecté ne doit pas pouvoir écrire au nom de la
     * plateforme : seule une clé de service le peut.
     */
    public function test_an_access_token_cannot_trigger_a_send(): void
    {
        $userToken = $this->postJson('/api/v1/auth/register', [
            'first_name' => 'Autre',
            'last_name' => 'Compte',
            'email' => 'autre@sekuu.com',
            'password' => 'un-mot-de-passe-long',
        ])->assertCreated()->json('data.access_token');

        $this->withToken($userToken)
            ->postJson('/api/v1/notifications', $this->payload(), $this->idempotency())
            ->assertStatus(401);
    }

    public function test_a_missing_key_is_refused(): void
    {
        $this->postJson('/api/v1/notifications', $this->payload(), $this->idempotency())
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }

    public function test_an_unknown_key_is_refused(): void
    {
        $this->withToken('sk_test_inexistante')
            ->postJson('/api/v1/notifications', $this->payload(), $this->idempotency())
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'API_KEY_INVALID');
    }

    public function test_a_key_without_the_scope_is_refused(): void
    {
        $readOnly = $this->app->make(IssueApiKey::class)->handle(
            organizationId: $this->organizationId,
            name: 'Lecture seule',
            scopes: ['notifications.read'],
            creator: $this->user,
        )->plainKey;

        $this->withToken($readOnly)
            ->postJson('/api/v1/notifications', $this->payload(), $this->idempotency())
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'INSUFFICIENT_PERMISSIONS');
    }

    public function test_a_revoked_key_is_indistinguishable_from_an_unknown_one(): void
    {
        ApiKey::query()->update(['revoked_at' => now()]);

        $this->withToken($this->apiKey)
            ->postJson('/api/v1/notifications', $this->payload(), $this->idempotency())
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'API_KEY_INVALID');
    }

    public function test_a_message_is_accepted_and_queued(): void
    {
        $this->withToken($this->apiKey)
            ->postJson('/api/v1/notifications', $this->payload(), $this->idempotency())
            // 202 et non 201 : la ressource créée est une intention.
            ->assertStatus(202)
            ->assertJsonPath('data.queued.0.channel', 'email');

        $this->assertDatabaseHas('notifications', [
            'template_key' => 'invitation.sent',
            'organization_id' => $this->organizationId,
        ]);
    }

    /**
     * L'organisation vient de la clé, jamais du corps : une clé ne peut pas
     * envoyer au nom d'une autre organisation.
     */
    public function test_the_organisation_comes_from_the_key(): void
    {
        $other = Organization::create(['name' => 'Autre', 'slug' => 'autre']);

        $this->withToken($this->apiKey)->postJson(
            '/api/v1/notifications',
            $this->payload() + ['organization_id' => $other->id],
            $this->idempotency(),
        )->assertStatus(202);

        $this->assertSame(
            $this->organizationId,
            Notification::query()->firstOrFail()->organization_id,
        );
    }

    /**
     * Un envoi est un effet de bord non réversible : sans clé, un rejeu réseau
     * produirait un doublon.
     */
    public function test_the_idempotency_key_is_mandatory(): void
    {
        $this->withToken($this->apiKey)
            ->postJson('/api/v1/notifications', $this->payload())
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');
    }

    public function test_a_replayed_request_does_not_duplicate(): void
    {
        $headers = $this->idempotency();

        $this->withToken($this->apiKey)->postJson('/api/v1/notifications', $this->payload(), $headers);
        $this->withToken($this->apiKey)->postJson('/api/v1/notifications', $this->payload(), $headers);

        $this->assertSame(1, Notification::query()->count());
    }

    public function test_a_suppressed_recipient_is_reported_synchronously(): void
    {
        Suppression::create([
            'channel' => 'email',
            'destination' => 'john@gmail.com',
            'reason' => Suppression::HARD_BOUNCE,
        ]);

        $this->withToken($this->apiKey)
            ->postJson('/api/v1/notifications', $this->payload(), $this->idempotency())
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'RECIPIENT_SUPPRESSED');
    }

    public function test_an_unknown_template_is_reported(): void
    {
        $this->withToken($this->apiKey)->postJson(
            '/api/v1/notifications',
            ['template_key' => 'inexistant', 'recipient' => ['email' => 'john@gmail.com']],
            $this->idempotency(),
        )->assertStatus(404)->assertJsonPath('error.code', 'TEMPLATE_NOT_FOUND');
    }

    public function test_a_recipient_without_any_coordinate_is_refused(): void
    {
        // Coordonnées présentes mais toutes vides : la validation les accepte,
        // c'est le cas d'usage qui doit trancher.
        $this->withToken($this->apiKey)->postJson(
            '/api/v1/notifications',
            ['template_key' => 'invitation.sent', 'recipient' => ['email' => null, 'phone' => null]],
            $this->idempotency(),
        )->assertStatus(422)->assertJsonPath('error.code', 'CHANNEL_NOT_AVAILABLE');
    }

    /**
     * Un destinataire supprimé n'invalide pas les autres.
     */
    public function test_a_bulk_reports_each_message_independently(): void
    {
        Suppression::create([
            'channel' => 'email',
            'destination' => 'bloque@gmail.com',
            'reason' => Suppression::HARD_BOUNCE,
        ]);

        $response = $this->withToken($this->apiKey)->postJson('/api/v1/notifications/bulk', [
            'template_key' => 'invitation.sent',
            'messages' => [
                ['recipient' => ['email' => 'bloque@gmail.com'], 'variables' => $this->variables()],
                ['recipient' => ['email' => 'ok@gmail.com'], 'variables' => $this->variables()],
            ],
        ], $this->idempotency())->assertStatus(202);

        $results = $response->json('data.results');

        $this->assertFalse($results[0]['accepted']);
        $this->assertSame('RECIPIENT_SUPPRESSED', $results[0]['error']['code']);
        $this->assertTrue($results[1]['accepted']);
    }

    public function test_a_scheduled_message_can_be_cancelled(): void
    {
        // Le pilote de file synchrone ignore le délai et livrerait aussitôt :
        // on retient la tâche pour observer l'état `queued`.
        Queue::fake();

        $id = $this->withToken($this->apiKey)->postJson(
            '/api/v1/notifications',
            $this->payload() + ['scheduled_for' => now()->addDay()->toIso8601ZuluString()],
            $this->idempotency(),
        )->assertStatus(202)->json('data.queued.0.id');

        $this->withToken($this->apiKey)->postJson("/api/v1/notifications/{$id}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', Notification::CANCELLED);
    }

    /**
     * Un message déjà remis au fournisseur ne se rattrape pas : mieux vaut le
     * dire que laisser croire à une annulation.
     */
    public function test_a_sent_message_cannot_be_cancelled(): void
    {
        $id = $this->withToken($this->apiKey)
            ->postJson('/api/v1/notifications', $this->payload(), $this->idempotency())
            ->json('data.queued.0.id');

        // Sans `scheduled_for`, la file synchrone l'a déjà livré.
        $this->withToken($this->apiKey)->postJson("/api/v1/notifications/{$id}/cancel")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'NOTIFICATION_NOT_CANCELLABLE');
    }

    // ------------------------------------------------------------ fixtures --

    /**
     * @return array<string, string>
     */
    private function idempotency(): array
    {
        return ['Idempotency-Key' => (string) Str::uuid()];
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        return [
            'template_key' => 'invitation.sent',
            'recipient' => ['email' => 'john@gmail.com'],
            'variables' => $this->variables(),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function variables(): array
    {
        return [
            'organization_name' => 'SOS Clinique',
            'role' => 'member',
            'accept_url' => 'https://app.sekuu.com/invitations/abc',
            'expires_at' => '2026-08-10',
        ];
    }
}
