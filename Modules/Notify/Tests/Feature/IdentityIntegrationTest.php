<?php

declare(strict_types=1);

namespace Modules\Notify\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Modules\Identity\Domain\Models\GlobalRole;
use Modules\Notify\Domain\Models\Notification;
use Modules\Notify\Domain\Models\Suppression;
use Tests\TestCase;

/**
 * Le bout en bout : une requête HTTP sur Identity doit produire un message.
 *
 * C'est ce qui supprime le contournement provisoire consistant à renvoyer les
 * jetons dans la réponse API.
 *
 * @see docs/03-services/notify/04-events.md
 */
final class IdentityIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'un-mot-de-passe-long';

    public function test_registering_produces_a_welcome_message(): void
    {
        $this->register();

        $notification = Notification::query()->where('template_key', 'user.welcome')->firstOrFail();

        $this->assertSame('nathan@sekuu.com', $notification->recipient);
        $this->assertSame(Notification::SENT, $notification->status);
        $this->assertCount(1, $this->sentMessages());
    }

    public function test_the_verification_link_reaches_the_message(): void
    {
        $token = $this->register()['email_verification_token'];

        $notification = Notification::query()->where('template_key', 'user.welcome')->firstOrFail();

        // Le jeton doit se retrouver dans le message — c'est tout l'objet.
        $this->assertStringContainsString($token, $notification->rendered_body);

        // Mais jamais dans le payload, qui est exposé par l'API.
        $this->assertStringNotContainsString($token, (string) json_encode($notification->payload));
    }

    public function test_forgotten_password_produces_a_reset_message(): void
    {
        $this->register();
        $this->flushHeaders();

        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'nathan@sekuu.com'])
            ->assertStatus(202);

        $notification = Notification::query()->where('template_key', 'password.reset')->firstOrFail();

        $this->assertSame('nathan@sekuu.com', $notification->recipient);
        $this->assertSame('transactional', $notification->category);
    }

    public function test_an_unknown_address_produces_no_message(): void
    {
        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'personne@sekuu.com'])
            ->assertStatus(202);

        $this->assertSame(0, Notification::query()->count());
    }

    public function test_resetting_a_password_also_sends_a_security_alert(): void
    {
        $this->register();
        $this->flushHeaders();

        $token = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'nathan@sekuu.com'])
            ->json('data.token');

        $this->postJson('/api/v1/auth/reset-password', [
            'token' => $token,
            'password' => 'un-nouveau-mot-de-passe',
        ])->assertOk();

        // Si l'utilisateur n'est pas à l'origine de la réinitialisation, c'est
        // ce message qui le lui apprend.
        $this->assertDatabaseHas('notifications', ['template_key' => 'password.changed']);
    }

    public function test_inviting_someone_produces_an_invitation_message(): void
    {
        $token = $this->register()['access_token'];

        $organizationId = $this->withToken($token)
            ->postJson('/api/v1/organizations', ['name' => 'SOS Clinique'])
            ->assertCreated()->json('data.id');

        $contextToken = $this->withToken($token)
            ->postJson('/api/v1/auth/switch-organization', ['organization_id' => $organizationId])
            ->assertOk()->json('data.access_token');

        $roleId = GlobalRole::query()->where('slug', 'member')->firstOrFail()->id;

        $this->withToken($contextToken)
            ->postJson("/api/v1/organizations/{$organizationId}/invitations", [
                'email' => 'john@gmail.com',
                'global_role_id' => $roleId,
            ])->assertCreated();

        $notification = Notification::query()->where('template_key', 'invitation.sent')->firstOrFail();

        $this->assertSame('john@gmail.com', $notification->recipient);
        $this->assertSame($organizationId, $notification->organization_id);
        $this->assertStringContainsString('SOS Clinique', $notification->rendered_body);
    }

    /**
     * Le request_id traverse deux modules : une notification reste rattachable
     * à la requête HTTP qui l'a déclenchée.
     */
    public function test_the_request_id_is_propagated_across_modules(): void
    {
        $response = $this->postJson('/api/v1/auth/register', $this->registrationPayload());

        $notification = Notification::query()->where('template_key', 'user.welcome')->firstOrFail();

        $this->assertSame($response->json('meta.request_id'), $notification->request_id);
    }

    /**
     * Un rebond dur bloque tout, y compris un lien de réinitialisation :
     * une adresse qui rebondit durablement n'est plus une adresse.
     */
    public function test_a_suppressed_address_blocks_the_reset_link_without_breaking_the_api(): void
    {
        $this->register();
        $this->flushHeaders();

        Suppression::create([
            'channel' => 'email',
            'destination' => 'nathan@sekuu.com',
            'reason' => Suppression::HARD_BOUNCE,
        ]);

        // L'API d'Identity ne doit pas échouer parce qu'un message ne part pas.
        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'nathan@sekuu.com'])
            ->assertStatus(202);

        $notification = Notification::query()->where('template_key', 'password.reset')->firstOrFail();

        $this->assertSame(Notification::SUPPRESSED, $notification->status);
        $this->assertSame('RECIPIENT_SUPPRESSED', $notification->failed_reason);
    }

    /**
     * La livraison des événements est « au moins une fois » : demander deux
     * fois un lien produit deux événements distincts, donc deux messages —
     * mais un même événement rejoué n'en produit qu'un.
     */
    public function test_each_request_produces_exactly_one_message(): void
    {
        $this->register();
        $this->flushHeaders();

        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'nathan@sekuu.com']);
        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'nathan@sekuu.com']);

        $this->assertSame(
            2,
            Notification::query()->where('template_key', 'password.reset')->count(),
        );
    }

    private function sentMessages(): Collection
    {
        return collect(Mail::mailer()->getSymfonyTransport()->messages());
    }

    /**
     * @return array<string, string>
     */
    private function registrationPayload(): array
    {
        return [
            'first_name' => 'Nathan',
            'last_name' => 'Tchinda',
            'email' => 'nathan@sekuu.com',
            'password' => self::PASSWORD,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function register(): array
    {
        return $this->postJson('/api/v1/auth/register', $this->registrationPayload())
            ->assertCreated()
            ->json('data');
    }
}
