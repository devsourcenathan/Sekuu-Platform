<?php

declare(strict_types=1);

namespace Modules\Notify\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Notify\Application\Sending\SendNotification;
use Modules\Notify\Application\Sending\SendRequest;
use Modules\Notify\Domain\Models\Suppression;
use Modules\Notify\Tests\Concerns\UsesApiKey;
use Tests\TestCase;

/**
 * @see docs/04-decisions/adr-0006-transactional-vs-marketing.md
 */
final class SuppressionApiTest extends TestCase
{
    use RefreshDatabase;
    use UsesApiKey;

    public function test_listing_requires_a_read_scope(): void
    {
        $this->getJson('/api/v1/suppressions')->assertStatus(401);

        $this->withToken($this->issueKey(['notifications.send']))
            ->getJson('/api/v1/suppressions')
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'INSUFFICIENT_PERMISSIONS');
    }

    /**
     * Un utilisateur connecté n'a pas à toucher une liste qui protège la
     * réputation de tout le domaine.
     */
    public function test_an_access_token_cannot_manage_suppressions(): void
    {
        $userToken = $this->postJson('/api/v1/auth/register', [
            'first_name' => 'Autre',
            'last_name' => 'Compte',
            'email' => 'autre@sekuu.com',
            'password' => 'un-mot-de-passe-long',
        ])->assertCreated()->json('data.access_token');

        $this->withToken($userToken)->getJson('/api/v1/suppressions')->assertStatus(401);
    }

    public function test_suppressions_can_be_listed(): void
    {
        $this->suppress('mort@sekuu.com', Suppression::HARD_BOUNCE);
        $this->suppress('plainte@sekuu.com', Suppression::COMPLAINT);

        $this->withToken($this->issueKey(['notifications.read']))
            ->getJson('/api/v1/suppressions')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonStructure(['meta' => ['next_cursor', 'has_more']]);
    }

    public function test_suppressions_can_be_filtered(): void
    {
        $this->suppress('mort@sekuu.com', Suppression::HARD_BOUNCE);
        $this->suppress('plainte@sekuu.com', Suppression::COMPLAINT);

        $key = $this->issueKey(['notifications.read']);

        $this->withToken($key)->getJson('/api/v1/suppressions?filter[reason]=complaint')
            ->assertOk()->assertJsonCount(1, 'data');

        // On cherche souvent une adresse dont on ne se rappelle qu'un fragment.
        $this->withToken($key)->getJson('/api/v1/suppressions?filter[destination]=plainte')
            ->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_an_unknown_filter_is_rejected(): void
    {
        $this->withToken($this->issueKey(['notifications.read']))
            ->getJson('/api/v1/suppressions?filter[secret]=x')
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'INVALID_FILTER');
    }

    public function test_a_destination_can_be_suppressed_manually(): void
    {
        $this->withToken($this->issueKey(['notifications.manage']))
            ->postJson('/api/v1/suppressions', ['channel' => 'email', 'destination' => 'Indesirable@Sekuu.COM'])
            ->assertCreated()
            // Sans normalisation, la casse permettrait de contourner la liste.
            ->assertJsonPath('data.destination', 'indesirable@sekuu.com')
            ->assertJsonPath('data.reason', Suppression::MANUAL);
    }

    public function test_suppressing_twice_is_refused(): void
    {
        $this->suppress('mort@sekuu.com', Suppression::HARD_BOUNCE);

        $this->withToken($this->issueKey(['notifications.manage']))
            ->postJson('/api/v1/suppressions', ['channel' => 'email', 'destination' => 'mort@sekuu.com'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'DUPLICATE_RESOURCE');
    }

    /**
     * Le manque que cette API vient combler : sans elle, un faux positif d'un
     * fournisseur bloquait définitivement une adresse valide, y compris son
     * lien de réinitialisation, sans autre recours qu'une requête SQL.
     */
    public function test_a_rehabilitated_address_receives_again(): void
    {
        $this->suppress('valide@sekuu.com', Suppression::HARD_BOUNCE);

        $id = Suppression::query()->firstOrFail()->id;

        $this->withToken($this->issueKey(['notifications.manage']))
            ->deleteJson('/api/v1/suppressions/'.$id)
            ->assertNoContent();

        $outcome = $this->app->make(SendNotification::class)->handle(SendRequest::toEmail(
            templateKey: 'password.reset',
            email: 'valide@sekuu.com',
            variables: [
                'first_name' => 'Nathan',
                'reset_url' => 'https://app.sekuu.com/reset?token=abc',
                'expires_in_hours' => '1',
            ],
        ));

        $this->assertTrue($outcome->sentAnything());
    }

    public function test_rehabilitation_requires_the_manage_scope(): void
    {
        $this->suppress('mort@sekuu.com', Suppression::HARD_BOUNCE);
        $id = Suppression::query()->firstOrFail()->id;

        $this->withToken($this->issueKey(['notifications.read']))
            ->deleteJson('/api/v1/suppressions/'.$id)
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'INSUFFICIENT_PERMISSIONS');
    }

    public function test_an_unknown_suppression_is_reported(): void
    {
        $this->withToken($this->issueKey(['notifications.manage']))
            ->deleteJson('/api/v1/suppressions/'.Str::uuid())
            ->assertNotFound()
            ->assertJsonPath('error.code', 'SUPPRESSION_NOT_FOUND');
    }

    private function suppress(string $destination, string $reason): void
    {
        Suppression::create([
            'channel' => 'email',
            'destination' => Suppression::normalise($destination),
            'reason' => $reason,
        ]);
    }
}
