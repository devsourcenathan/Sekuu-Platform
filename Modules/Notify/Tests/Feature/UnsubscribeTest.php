<?php

declare(strict_types=1);

namespace Modules\Notify\Tests\Feature;

use App\Platform\Exceptions\DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Notify\Application\Preferences\UnsubscribeToken;
use Modules\Notify\Application\Sending\SendNotification;
use Modules\Notify\Application\Sending\SendRequest;
use Modules\Notify\Domain\Channel;
use Modules\Notify\Domain\Models\Suppression;
use Tests\TestCase;

/**
 * @see docs/04-decisions/adr-0006-transactional-vs-marketing.md
 */
final class UnsubscribeTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_forged_token_is_refused(): void
    {
        $this->getJson('/api/v1/preferences/unsubscribe/nimporte-quoi')
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'UNSUBSCRIBE_TOKEN_INVALID');
    }

    public function test_a_tampered_token_is_refused(): void
    {
        $token = UnsubscribeToken::issue(Channel::EMAIL, 'john@gmail.com', 'operational');
        [$body] = explode('.', $token, 2);

        // Corps modifié, signature d'origine : la signature ne colle plus.
        $this->getJson('/api/v1/preferences/unsubscribe/'.$body.'.signature-invalide')
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'UNSUBSCRIBE_TOKEN_INVALID');
    }

    /**
     * Exiger une connexion pour se désabonner est une pratique hostile, et
     * pousse vers le bouton « spam ».
     */
    public function test_the_context_is_readable_without_being_signed_in(): void
    {
        $token = UnsubscribeToken::issue(Channel::EMAIL, 'john@gmail.com', 'operational');

        $this->getJson('/api/v1/preferences/unsubscribe/'.$token)
            ->assertOk()
            ->assertJsonPath('data.category', 'operational')
            ->assertJsonPath('data.editable', true)
            // L'adresse est masquée : le lien peut circuler.
            ->assertJsonPath('data.destination', 'j***@gmail.com');
    }

    public function test_a_known_user_only_loses_the_category(): void
    {
        $userId = (string) Str::uuid();
        $token = UnsubscribeToken::issue(Channel::EMAIL, 'membre@sekuu.com', 'operational', $userId);

        $this->postJson('/api/v1/preferences/unsubscribe/'.$token)
            ->assertOk()
            ->assertJsonPath('data.effect', 'preference');

        $this->assertDatabaseHas('notification_preferences', [
            'user_id' => $userId,
            'category' => 'operational',
            'channel' => 'email',
            'enabled' => false,
        ]);

        // Aucune suppression : le transactionnel doit continuer de passer.
        $this->assertDatabaseCount('suppressions', 0);
    }

    /**
     * Le point le plus important : se désabonner des invitations ne doit pas
     * couper le lien de réinitialisation de mot de passe.
     */
    public function test_unsubscribing_never_blocks_transactional_messages(): void
    {
        $userId = (string) Str::uuid();
        $token = UnsubscribeToken::issue(Channel::EMAIL, 'membre@sekuu.com', 'operational', $userId);

        $this->postJson('/api/v1/preferences/unsubscribe/'.$token)->assertOk();

        $outcome = $this->app->make(SendNotification::class)->handle(SendRequest::toEmail(
            templateKey: 'password.reset',
            email: 'membre@sekuu.com',
            variables: [
                'first_name' => 'Membre',
                'reset_url' => 'https://app.sekuu.com/reset?token=abc',
                'expires_in_hours' => '1',
            ],
            userId: $userId,
        ));

        $this->assertTrue($outcome->sentAnything());
    }

    public function test_an_operational_message_is_blocked_after_unsubscribing(): void
    {
        $userId = (string) Str::uuid();
        $token = UnsubscribeToken::issue(Channel::EMAIL, 'membre@sekuu.com', 'operational', $userId);

        $this->postJson('/api/v1/preferences/unsubscribe/'.$token)->assertOk();

        $this->expectException(DomainException::class);

        $this->app->make(SendNotification::class)->handle(SendRequest::toEmail(
            templateKey: 'invitation.sent',
            email: 'membre@sekuu.com',
            variables: [
                'organization_name' => 'SOS Clinique',
                'role' => 'member',
                'accept_url' => 'https://app.sekuu.com/invitations/abc',
                'expires_at' => '2026-08-10',
            ],
            userId: $userId,
        ));
    }

    /**
     * Sans compte auquel rattacher une préférence, le seul outil disponible
     * est la liste de suppression — qui bloque toute la destination.
     */
    public function test_an_unknown_recipient_is_suppressed_entirely(): void
    {
        $token = UnsubscribeToken::issue(Channel::EMAIL, 'invite@gmail.com', 'operational');

        $this->postJson('/api/v1/preferences/unsubscribe/'.$token)
            ->assertOk()
            ->assertJsonPath('data.effect', 'suppression');

        $this->assertDatabaseHas('suppressions', [
            'destination' => 'invite@gmail.com',
            'reason' => Suppression::UNSUBSCRIBE,
            'source' => 'unsubscribe-link',
        ]);
    }

    public function test_a_transactional_category_cannot_be_unsubscribed(): void
    {
        $token = UnsubscribeToken::issue(Channel::EMAIL, 'membre@sekuu.com', 'transactional', (string) Str::uuid());

        $this->postJson('/api/v1/preferences/unsubscribe/'.$token)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'TRANSACTIONAL_CANNOT_BE_DISABLED');
    }

    /**
     * Un message peut être rouvert des mois plus tard : un lien périmé
     * n'aiderait personne, et pousserait vers le signalement.
     */
    public function test_the_link_does_not_expire(): void
    {
        $token = UnsubscribeToken::issue(Channel::EMAIL, 'john@gmail.com', 'marketing');

        $this->travel(400)->days();

        $this->getJson('/api/v1/preferences/unsubscribe/'.$token)->assertOk();
    }
}
