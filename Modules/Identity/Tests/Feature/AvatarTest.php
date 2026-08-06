<?php

declare(strict_types=1);

namespace Modules\Identity\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Identity\Application\Profile\AvatarFiles;
use Modules\Identity\Domain\Models\User;
use Modules\Storage\Domain\Models\Destination;
use Tests\Concerns\SignsInAsOwner;
use Tests\TestCase;

/**
 * La photo de profil : le premier fichier **déposé par une personne**.
 *
 * Le PDF de facture est produit par le serveur ; celui-ci emprunte le chemin en
 * trois temps — déclarer, écrire, confirmer — celui qu'un client utilisera pour
 * une vidéo de cours.
 *
 * @see docs/03-services/storage/05-integration.md
 */
final class AvatarTest extends TestCase
{
    use RefreshDatabase;
    use SignsInAsOwner;

    /** Un PNG de 1×1 pixel, transparent. */
    private const PNG = "\x89PNG\r\n\x1a\n\x00\x00\x00\x0dIHDR\x00\x00\x00\x01\x00\x00\x00\x01\x08\x06\x00\x00\x00\x1f\x15\xc4\x89\x00\x00\x00\x0aIDATx\x9cc\x00\x01\x00\x00\x05\x00\x01\x0d\x0a\x2d\xb4\x00\x00\x00\x00IEND\xaeB\x60\x82";

    private string $userId;

    protected function setUp(): void
    {
        parent::setUp();

        Destination::query()->create([
            'slug' => 'avatars',
            'driver' => 'local',
            'config' => ['root' => storage_path('framework/testing/avatars')],
            'environment' => app()->environment(),
            'status' => Destination::ACTIVE,
            'is_default' => true,
            'verified_at' => now(),
        ]);

        $this->signInAsOwner();
        $this->withToken($this->ownerToken);

        $this->userId = (string) $this->getJson('/api/v1/auth/me')->assertOk()->json('data.user.id');
    }

    public function test_a_user_deposits_their_own_photo(): void
    {
        $declared = $this->declare($this->userId);

        $this->call('PUT', $declared['upload_url'], content: self::PNG)->assertOk();

        $this->postJson("/api/v1/files/{$declared['id']}/confirm")
            ->assertOk()
            ->assertJsonPath('data.mime_type', 'image/png');

        $this->assertSame($declared['id'], User::query()->find($this->userId)->avatar_file_id);

        // Une image est servie **en ligne** : c'est le seul endroit où un
        // fichier déposé par un tiers l'est.
        $this->getJson("/api/v1/files/{$declared['id']}/url")
            ->assertOk()
            ->assertJsonPath('data.disposition', 'inline');
    }

    /**
     * Changer le visage de quelqu'un d'autre n'est pas une opération
     * d'administration, c'est une usurpation. Le rôle `Owner` n'y change rien.
     */
    public function test_nobody_deposits_a_photo_on_someone_elses_profile(): void
    {
        $other = User::query()->create([
            'first_name' => 'Ada', 'last_name' => 'Lovelace',
            'email' => 'ada@sekuu.com', 'password' => bcrypt('un-mot-de-passe-long'),
        ]);

        $this->postJson('/api/v1/files', [
            'owner_type' => AvatarFiles::TYPE,
            'owner_id' => (string) $other->id,
            'name' => 'photo.png',
            'mime_type' => 'image/png',
            'size' => 100,
        ])->assertForbidden();
    }

    /**
     * Un SVG est un document qui peut porter du script, et un avatar est la
     * seule chose que la plateforme rende en ligne.
     */
    public function test_a_svg_is_refused(): void
    {
        $this->postJson('/api/v1/files', [
            'owner_type' => AvatarFiles::TYPE,
            'owner_id' => $this->userId,
            'name' => 'photo.svg',
            'mime_type' => 'image/svg+xml',
            'size' => 100,
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'MIME_TYPE_NOT_ALLOWED');
    }

    /**
     * Le type declare ne fait pas foi : c'est le magasin qui tranche.
     */
    public function test_declaring_an_image_and_writing_something_else_fails(): void
    {
        $declared = $this->declare($this->userId);

        $this->call('PUT', $declared['upload_url'], content: '<?php echo "bonjour";')->assertOk();

        $this->postJson("/api/v1/files/{$declared['id']}/confirm")
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'MIME_TYPE_NOT_ALLOWED');

        $this->assertNull(User::query()->find($this->userId)->avatar_file_id);
    }

    /**
     * Une photo se remplace, contrairement au PDF d'une facture : elle ne porte
     * aucune rétention.
     */
    public function test_a_photo_can_be_removed(): void
    {
        $declared = $this->declare($this->userId);
        $this->call('PUT', $declared['upload_url'], content: self::PNG);
        $this->postJson("/api/v1/files/{$declared['id']}/confirm")->assertOk();

        $this->deleteJson("/api/v1/files/{$declared['id']}")->assertNoContent();

        $this->assertNull(User::query()->find($this->userId)->avatar_file_id);
    }

    /**
     * @return array<string, mixed>
     */
    private function declare(string $userId): array
    {
        return $this->postJson('/api/v1/files', [
            'owner_type' => AvatarFiles::TYPE,
            'owner_id' => $userId,
            'name' => 'photo.png',
            'mime_type' => 'image/png',
            'size' => strlen(self::PNG),
        ])->assertCreated()->json('data');
    }
}
