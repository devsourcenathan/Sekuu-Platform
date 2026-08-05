<?php

declare(strict_types=1);

namespace Modules\Storage\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Identity\Domain\Models\ApiKey;
use Modules\Storage\Application\Files\OwnerRegistry;
use Modules\Storage\Domain\Models\Destination;
use Modules\Storage\Domain\Models\StoredFile;
use Modules\Storage\Tests\Support\FakeFileOwner;
use Tests\Concerns\SignsInAsOwner;
use Tests\TestCase;

/**
 * Stocker depuis un service externe, avec nos comptes ou les siens.
 *
 * Deux bornes tiennent l'ensemble, et il faut les deux : la clé porte la liste
 * des `owner_type` qu'elle peut manipuler, et elle porte son plafond de
 * rétention. Les deux valent zéro à l'émission — la clé **habilite**, elle
 * n'hérite de rien.
 *
 * @see docs/03-services/storage/07-external-api.md
 */
final class ExternalStorageTest extends TestCase
{
    use RefreshDatabase;
    use SignsInAsOwner;

    private string $plainKey;

    protected function setUp(): void
    {
        parent::setUp();

        FakeFileOwner::reset();
        app(OwnerRegistry::class)->register(FakeFileOwner::TYPE, FakeFileOwner::class);

        Destination::query()->create([
            'slug' => 'plateforme',
            'driver' => 'local',
            'config' => ['root' => storage_path('framework/testing/plateforme')],
            'environment' => app()->environment(),
            'status' => Destination::ACTIVE,
            'is_default' => true,
            'verified_at' => now(),
        ]);

        $this->signInAsOwner();
        $this->plainKey = $this->issueKey([FakeFileOwner::TYPE]);
    }

    public function test_a_product_can_store_with_its_key_alone(): void
    {
        $declared = $this->asProduct()->postJson('/api/v1/files', [
            'owner_type' => FakeFileOwner::TYPE,
            'owner_id' => 'lecon-1',
            'name' => 'seance.mp4',
            'mime_type' => 'video/mp4',
            'size' => 11,
        ])->assertCreated()->json('data');

        $this->call('PUT', $declared['upload_url'], content: 'hello-world')->assertOk();

        $this->asProduct()
            ->postJson("/api/v1/files/{$declared['id']}/confirm")
            ->assertOk()
            ->assertJsonPath('data.status', 'ready');

        // Aucun utilisateur derrière une clé : inventer un identifiant ferait
        // entrer un acteur fictif dans le journal des accès.
        $this->assertNull(StoredFile::query()->find($declared['id'])->uploaded_by);
    }

    /**
     * Une clé de Learn ne touche pas un `billing.invoice`.
     */
    public function test_a_key_cannot_touch_a_type_outside_its_allowlist(): void
    {
        $this->asProduct()->postJson('/api/v1/files', [
            'owner_type' => 'billing.invoice',
            'owner_id' => (string) Str::uuid(),
            'name' => 'facture.pdf',
            'mime_type' => 'application/pdf',
            'size' => 10,
        ])->assertForbidden();
    }

    /**
     * Déposer et enregistrer un magasin sont deux dangers différents ; un seul
     * droit pour les deux serait le plus large des deux.
     */
    public function test_a_write_key_cannot_register_a_store(): void
    {
        $this->asProduct()->postJson('/api/v1/storage/destinations', [
            'slug' => 'chez-moi',
            'driver' => 'local',
            'config' => ['root' => storage_path('framework/testing/chez-moi')],
            'environment' => app()->environment(),
        ])->assertForbidden();
    }

    /**
     * Le plafond vaut zéro à l'émission : un produit ne peut rendre aucun octet
     * indestructible tant qu'on ne le lui a pas accordé.
     */
    public function test_retention_is_refused_by_default_and_says_the_bound(): void
    {
        $this->asProduct()->postJson('/api/v1/files', [
            'owner_type' => FakeFileOwner::TYPE,
            'owner_id' => 'lecon-1',
            'name' => 'contrat.pdf',
            'mime_type' => 'application/pdf',
            'size' => 10,
            'retain_days' => 3650,
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'FILE_RETENTION_TOO_LONG');
    }

    /**
     * Jamais de rabotage silencieux : un produit qui croit avoir dix ans et en
     * obtient un ne s'en apercevrait qu'au moment où le document manque.
     */
    public function test_an_allowed_retention_is_applied_exactly(): void
    {
        $key = $this->issueKey([FakeFileOwner::TYPE], maxRetentionDays: 365);

        $declared = $this->withToken($key)->postJson('/api/v1/files', [
            'owner_type' => FakeFileOwner::TYPE,
            'owner_id' => 'lecon-1',
            'name' => 'contrat.pdf',
            'mime_type' => 'application/pdf',
            'size' => 10,
            'retain_days' => 365,
        ])->assertCreated()->json('data');

        $this->assertSame(
            now()->addDays(365)->toDateString(),
            StoredFile::query()->find($declared['id'])->retain_until->toDateString(),
        );
    }

    /**
     * Un produit apporte son magasin, et l'épreuve tranche avant qu'il ne
     * serve. Ses identifiants ne ressortent jamais — une empreinte, rien de
     * plus.
     */
    public function test_a_product_registers_its_own_store_and_it_is_probed(): void
    {
        $key = $this->issueKey([FakeFileOwner::TYPE], scopes: ['storage.destinations']);

        $destination = $this->withToken($key)->postJson('/api/v1/storage/destinations', [
            'slug' => 'chez-acme',
            'driver' => 'local',
            'config' => ['root' => storage_path('framework/testing/chez-acme')],
            'credentials' => ['key' => 'AKIAEXAMPLE7X2Q', 'secret' => 'jamais-rendu'],
            'environment' => app()->environment(),
        ])->assertCreated()->json('data');

        $this->assertSame(Destination::ACTIVE, $destination['status']);
        $this->assertArrayNotHasKey('credentials', $destination);
        $this->assertStringContainsString('7X2Q', (string) $destination['credential_fingerprint']);
    }

    /**
     * Sans clé ni jeton, rien. Les deux schémas partagent l'en-tête
     * `Authorization` et sont résolus dans le contrôleur ; l'absence des deux
     * doit rester un refus franc.
     */
    public function test_an_anonymous_call_is_refused(): void
    {
        $this->flushHeaders();

        $this->postJson('/api/v1/files', [
            'owner_type' => FakeFileOwner::TYPE,
            'owner_id' => 'lecon-1',
            'name' => 'x.pdf',
            'mime_type' => 'application/pdf',
            'size' => 10,
        ])->assertUnauthorized();
    }

    private function asProduct(): self
    {
        $this->flushHeaders();

        return $this->withToken($this->plainKey);
    }

    /**
     * @param  list<string>  $ownerTypes
     * @param  list<string>  $scopes
     */
    private function issueKey(
        array $ownerTypes,
        array $scopes = ['storage.write', 'storage.read'],
        int $maxRetentionDays = 0,
    ): string {
        $plain = 'sk_test_'.Str::random(48);

        ApiKey::create([
            'organization_id' => $this->organizationId,
            'name' => 'Sekuu Learn',
            'prefix' => 'sk_test_',
            'key_hash' => ApiKey::hash($plain),
            'scopes' => $scopes,
            'subject_types' => $ownerTypes,
            'max_retention_days' => $maxRetentionDays,
        ]);

        return $plain;
    }
}
