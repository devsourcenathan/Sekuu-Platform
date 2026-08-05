<?php

declare(strict_types=1);

namespace Modules\Storage\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Storage\Application\Files\OwnerRegistry;
use Modules\Storage\Domain\Models\Destination;
use Modules\Storage\Domain\Models\FileDownload;
use Modules\Storage\Domain\Models\StoredFile;
use Modules\Storage\Tests\Support\FakeFileOwner;
use Tests\Concerns\SignsInAsOwner;
use Tests\TestCase;

/**
 * La chaîne complète, contre un vrai magasin.
 *
 * Déclarer, écrire, confirmer, lire, supprimer — sans compte externe et sans
 * réseau, grâce au pilote local. C'est précisément ce qui manquait au canal SMS
 * de Notify : intégralement écrit, jamais exécuté, et faux sur trois points le
 * jour du premier envoi.
 *
 * @see docs/03-services/storage/03-api.md
 */
final class FileLifecycleTest extends TestCase
{
    use RefreshDatabase;
    use SignsInAsOwner;

    private Destination $destination;

    protected function setUp(): void
    {
        parent::setUp();

        FakeFileOwner::reset();

        app(OwnerRegistry::class)->register(FakeFileOwner::TYPE, FakeFileOwner::class);

        $this->destination = Destination::query()->create([
            'slug' => 'tests',
            'driver' => 'local',
            'config' => ['root' => storage_path('framework/testing/storage-module')],
            'environment' => app()->environment(),
            'status' => Destination::ACTIVE,
            'is_default' => true,
            'verified_at' => now(),
        ]);

        $this->signInAsOwner();
        $this->withToken($this->ownerToken);
    }

    public function test_the_whole_chain_from_declaration_to_deletion(): void
    {
        $declared = $this->postJson('/api/v1/files', [
            'owner_type' => FakeFileOwner::TYPE,
            'owner_id' => 'lecon-1',
            'name' => 'cours.pdf',
            'mime_type' => 'application/pdf',
            'size' => 11,
        ])->assertCreated()->json('data');

        $this->assertSame('pending', $declared['status']);
        $this->assertSame('PUT', $declared['upload_method']);

        // Le client écrit là où on lui dit, sans rien savoir du magasin.
        $this->call('PUT', $declared['upload_url'], content: 'hello-world')->assertOk();

        $confirmed = $this->postJson("/api/v1/files/{$declared['id']}/confirm")
            ->assertOk()
            ->json('data');

        $this->assertSame('ready', $confirmed['status']);

        // Constaté, jamais déclaré : la taille est celle des octets écrits.
        $this->assertSame(11, $confirmed['size']);
        $this->assertSame([$declared['id']], FakeFileOwner::$attached);

        $url = $this->getJson("/api/v1/files/{$declared['id']}/url")->assertOk()->json('data');

        $this->assertNotEmpty($url['url']);
        $this->assertSame('inline', $url['disposition']);
        $this->assertSame(1, FileDownload::query()->where('file_id', $declared['id'])->count());

        $this->assertSame('hello-world', $this->get($url['url'])->assertOk()->streamedContent());

        $this->deleteJson("/api/v1/files/{$declared['id']}")->assertNoContent();

        $this->assertSame('deleted', StoredFile::query()->find($declared['id'])->status);
        $this->assertSame([$declared['id']], FakeFileOwner::$detached);
    }

    /**
     * Le cœur de la conception : ce que le client annonce ne fait jamais foi.
     */
    public function test_the_declaration_never_decides_the_outcome(): void
    {
        FakeFileOwner::$maxBytes = 20;

        $declared = $this->postJson('/api/v1/files', [
            'owner_type' => FakeFileOwner::TYPE,
            'owner_id' => 'lecon-1',
            'name' => 'petit.txt',
            'mime_type' => 'text/plain',

            // Le client jure que ce sera minuscule.
            'size' => 5,
        ])->assertCreated()->json('data');

        // Et écrit tout autre chose.
        $this->call('PUT', $declared['upload_url'], content: str_repeat('X', 200))->assertOk();

        $this->postJson("/api/v1/files/{$declared['id']}/confirm")
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'FILE_TOO_LARGE');

        // Refus constaté, et non incertitude : l'objet est effacé et le fichier
        // ne reste pas en attente d'un réessai qui ne peut pas aboutir.
        $this->assertSame('deleted', StoredFile::query()->find($declared['id'])->status);
    }

    public function test_a_declaration_without_bytes_stays_pending(): void
    {
        $declared = $this->postJson('/api/v1/files', [
            'owner_type' => FakeFileOwner::TYPE,
            'owner_id' => 'lecon-1',
            'name' => 'jamais.txt',
            'mime_type' => 'text/plain',
            'size' => 10,
        ])->assertCreated()->json('data');

        $this->postJson("/api/v1/files/{$declared['id']}/confirm")
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'UPLOAD_INCOMPLETE');

        // `pending` veut dire « on ne sait pas », pas « ça a échoué » : le
        // client peut réessayer tant que l'URL vit.
        $this->assertSame('pending', StoredFile::query()->find($declared['id'])->status);
    }

    public function test_a_refused_read_looks_exactly_like_a_missing_file(): void
    {
        $file = $this->readyFile();

        FakeFileOwner::$allowsRead = false;

        $this->getJson("/api/v1/files/{$file}/url")
            ->assertNotFound()
            ->assertJsonPath('error.code', 'FILE_NOT_FOUND');

        // Le même code qu'un identifiant inventé : sans quoi la route
        // deviendrait un oracle sur ce qui existe chez autrui.
        $this->getJson('/api/v1/files/019fd000-0000-7000-8000-000000000000/url')
            ->assertNotFound()
            ->assertJsonPath('error.code', 'FILE_NOT_FOUND');
    }

    public function test_retention_beats_everyone(): void
    {
        FakeFileOwner::$retainDays = 3650;

        $file = $this->readyFile();

        $this->deleteJson("/api/v1/files/{$file}")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'FILE_RETAINED');

        $this->assertSame('ready', StoredFile::query()->find($file)->status);
    }

    public function test_an_unready_file_has_no_read_url(): void
    {
        $declared = $this->postJson('/api/v1/files', [
            'owner_type' => FakeFileOwner::TYPE,
            'owner_id' => 'lecon-1',
            'name' => 'attente.txt',
            'mime_type' => 'text/plain',
            'size' => 10,
        ])->assertCreated()->json('data');

        $this->getJson("/api/v1/files/{$declared['id']}/url")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'FILE_NOT_READY');
    }

    public function test_an_unknown_owner_type_fails_hard(): void
    {
        $this->postJson('/api/v1/files', [
            'owner_type' => 'inconnu.chose',
            'owner_id' => 'x',
            'name' => 'x.txt',
            'mime_type' => 'text/plain',
            'size' => 10,
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'FILE_OWNER_TYPE_UNKNOWN');
    }

    /**
     * La clé de l'objet porte l'identifiant d'organisation : la rendre
     * publierait la structure du compartiment.
     */
    public function test_the_object_key_is_never_exposed(): void
    {
        $file = $this->readyFile();

        $body = $this->getJson("/api/v1/files/{$file}")->assertOk()->json('data');

        $this->assertArrayNotHasKey('path', $body);
        $this->assertArrayNotHasKey('destination_id', $body);
    }

    private function readyFile(): string
    {
        $declared = $this->postJson('/api/v1/files', [
            'owner_type' => FakeFileOwner::TYPE,
            'owner_id' => 'lecon-1',
            'name' => 'pret.pdf',
            'mime_type' => 'application/pdf',
            'size' => 11,
        ])->assertCreated()->json('data');

        $this->call('PUT', $declared['upload_url'], content: 'hello-world');
        $this->postJson("/api/v1/files/{$declared['id']}/confirm")->assertOk();

        return $declared['id'];
    }
}
