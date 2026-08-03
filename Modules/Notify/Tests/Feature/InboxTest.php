<?php

declare(strict_types=1);

namespace Modules\Notify\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Identity\Domain\Models\User;
use Modules\Notify\Application\Sending\SendNotification;
use Modules\Notify\Application\Sending\SendRequest;
use Modules\Notify\Domain\Channel;
use Modules\Notify\Domain\Models\Notification;
use Modules\Notify\Infrastructure\Providers\ProviderRegistry;
use Tests\TestCase;

/**
 * @see docs/03-services/notify/03-api.md
 */
final class InboxTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Le canal interne n'a pas de fournisseur externe : il reste opérationnel
     * quand tous les autres échouent.
     */
    public function test_the_internal_channel_needs_no_configuration(): void
    {
        $registry = $this->app->make(ProviderRegistry::class);

        $this->assertTrue($registry->hasChannel(Channel::IN_APP));
        $this->assertSame(
            ['in-app'],
            array_map(fn ($p) => $p->name(), $registry->forChannel(Channel::IN_APP)),
        );
    }

    public function test_an_internal_notification_is_delivered_without_any_provider(): void
    {
        [$token, $user] = $this->signIn();

        $this->sendInternal($user->id);

        $this->withToken($token)->getJson('/api/v1/inbox')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.template_key', 'organization.created');

        $this->assertSame(
            Notification::SENT,
            Notification::query()->where('channel', Channel::IN_APP)->firstOrFail()->status,
        );
    }

    /**
     * C'est le seul endroit où le corps d'un message est exposé : une
     * notification interne n'a pas d'autre support que cette réponse.
     */
    public function test_the_body_is_exposed_here_and_only_here(): void
    {
        [$token, $user] = $this->signIn();

        $this->sendInternal($user->id);

        // Aucune langue demandée : le template est rendu dans la langue par
        // défaut de la plateforme.
        $this->withToken($token)->getJson('/api/v1/inbox')
            ->assertOk()
            ->assertJsonPath('data.0.body', 'SOS Clinique has been created. You are its owner.');
    }

    public function test_the_requested_language_is_honoured(): void
    {
        [$token, $user] = $this->signIn();

        $this->app->make(SendNotification::class)->handle(new SendRequest(
            templateKey: 'organization.created',
            recipients: [Channel::IN_APP => $user->id],
            variables: ['organization_name' => 'SOS Clinique'],
            userId: $user->id,
            locale: 'fr',
        ));

        $this->withToken($token)->getJson('/api/v1/inbox')
            ->assertOk()
            ->assertJsonPath('data.0.body', 'SOS Clinique a été créée. Vous en êtes le propriétaire.');
    }

    public function test_the_unread_counter_follows_reading(): void
    {
        [$token, $user] = $this->signIn();

        $this->sendInternal($user->id, 'idem-1');
        $this->sendInternal($user->id, 'idem-2');

        $this->withToken($token)->getJson('/api/v1/inbox/unread-count')
            ->assertOk()
            ->assertJsonPath('data.unread', 2);

        $id = $this->withToken($token)->getJson('/api/v1/inbox')->json('data.0.id');

        $this->withToken($token)->postJson("/api/v1/inbox/{$id}/read")->assertOk();

        $this->withToken($token)->getJson('/api/v1/inbox/unread-count')
            ->assertJsonPath('data.unread', 1);
    }

    public function test_marking_as_read_is_idempotent(): void
    {
        [$token, $user] = $this->signIn();
        $this->sendInternal($user->id);

        $id = $this->withToken($token)->getJson('/api/v1/inbox')->json('data.0.id');

        $first = $this->withToken($token)->postJson("/api/v1/inbox/{$id}/read")->json('data.read_at');
        $second = $this->withToken($token)->postJson("/api/v1/inbox/{$id}/read")->json('data.read_at');

        // Relire ne déplace pas la date : sinon l'historique serait faux.
        $this->assertSame($first, $second);
    }

    public function test_everything_can_be_marked_as_read_at_once(): void
    {
        [$token, $user] = $this->signIn();

        $this->sendInternal($user->id, 'idem-1');
        $this->sendInternal($user->id, 'idem-2');

        $this->withToken($token)->postJson('/api/v1/inbox/read-all')
            ->assertOk()
            ->assertJsonPath('data.marked', 2);

        $this->withToken($token)->getJson('/api/v1/inbox/unread-count')
            ->assertJsonPath('data.unread', 0);
    }

    public function test_only_unread_notifications_can_be_listed(): void
    {
        [$token, $user] = $this->signIn();

        $this->sendInternal($user->id, 'idem-1');
        $this->sendInternal($user->id, 'idem-2');

        $id = $this->withToken($token)->getJson('/api/v1/inbox')->json('data.0.id');
        $this->withToken($token)->postJson("/api/v1/inbox/{$id}/read")->assertOk();

        $this->withToken($token)->getJson('/api/v1/inbox?unread=1')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    /**
     * La boîte d'autrui doit être indiscernable d'une boîte vide.
     */
    public function test_another_users_inbox_is_invisible(): void
    {
        [, $victim] = $this->signIn('victime@sekuu.com');
        $this->sendInternal($victim->id);

        $this->flushHeaders();
        [$attackerToken] = $this->signIn('intrus@sekuu.com');

        $this->withToken($attackerToken)->getJson('/api/v1/inbox')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_another_users_notification_cannot_be_marked_as_read(): void
    {
        [$victimToken, $victim] = $this->signIn('victime@sekuu.com');
        $this->sendInternal($victim->id);

        $id = $this->withToken($victimToken)->getJson('/api/v1/inbox')->json('data.0.id');

        $this->flushHeaders();
        [$attackerToken] = $this->signIn('intrus@sekuu.com');

        $this->withToken($attackerToken)->postJson("/api/v1/inbox/{$id}/read")
            ->assertNotFound()
            ->assertJsonPath('error.code', 'NOTIFICATION_NOT_FOUND');
    }

    // ------------------------------------------------------------ fixtures --

    /**
     * @return array{string, User}
     */
    private function signIn(string $email = 'nathan@sekuu.com'): array
    {
        $token = $this->postJson('/api/v1/auth/register', [
            'first_name' => 'Nathan',
            'last_name' => 'Tchinda',
            'email' => $email,
            'password' => 'un-mot-de-passe-long',
        ])->assertCreated()->json('data.access_token');

        return [$token, User::query()->where('email', $email)->firstOrFail()];
    }

    private function sendInternal(string $userId, ?string $idempotencyKey = null): void
    {
        $this->app->make(SendNotification::class)->handle(new SendRequest(
            templateKey: 'organization.created',
            recipients: [Channel::IN_APP => $userId],
            variables: ['first_name' => 'Nathan', 'organization_name' => 'SOS Clinique'],
            userId: $userId,
            idempotencyKey: $idempotencyKey,
        ));
    }
}
