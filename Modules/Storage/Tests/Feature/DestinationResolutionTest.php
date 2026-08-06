<?php

declare(strict_types=1);

namespace Modules\Storage\Tests\Feature;

use App\Platform\Contracts\FileActor;
use App\Platform\Contracts\FileRef;
use App\Platform\Events\DomainEvent;
use App\Platform\Exceptions\DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Modules\Storage\Application\Destinations\RegisterDestination;
use Modules\Storage\Application\Destinations\VerifyDestination;
use Modules\Storage\Application\Files\DeclaredFile;
use Modules\Storage\Application\Files\DeclareFile;
use Modules\Storage\Application\Files\OwnerRegistry;
use Modules\Storage\Domain\Models\Destination;
use Modules\Storage\Domain\Models\StoragePlacement;
use Modules\Storage\Domain\Models\StoredFile;
use Modules\Storage\Tests\Support\FakeFileOwner;
use Tests\TestCase;

/**
 * Où les octets atterrissent, et pourquoi ce n'est jamais une surprise.
 *
 * Quatre rangs, du plus précis au plus général. Un repli **déclaré**, jamais
 * deviné. Et une destination figée sur la ligne du fichier, pour qu'un
 * changement de règle ne rende pas illisible ce qui existe déjà.
 *
 * @see docs/03-services/storage/06-destinations.md
 * @see docs/04-decisions/adr-0014-storage-destinations.md
 */
final class DestinationResolutionTest extends TestCase
{
    use RefreshDatabase;

    private const ORG = '019fd000-0000-7000-8000-0000000000aa';

    protected function setUp(): void
    {
        parent::setUp();

        FakeFileOwner::reset();
        app(OwnerRegistry::class)->register(FakeFileOwner::TYPE, FakeFileOwner::class);
    }

    public function test_the_platform_default_serves_when_nothing_else_says_otherwise(): void
    {
        $default = $this->destination('defaut', isDefault: true);

        $this->assertSame($default->id, $this->declare()->file->destination_id);
    }

    public function test_a_placement_beats_the_default(): void
    {
        $this->destination('defaut', isDefault: true);
        $client = $this->destination('chez-le-client');

        StoragePlacement::query()->create([
            'organization_id' => self::ORG,
            'owner_type' => null,
            'destination_id' => $client->id,
        ]);

        $this->assertSame($client->id, $this->declare()->file->destination_id);
    }

    /**
     * Une règle typée est la plus délibérée : c'est celle qu'on a écrite en
     * pensant précisément à ce type d'objet.
     */
    public function test_a_typed_placement_beats_a_catch_all(): void
    {
        $this->destination('defaut', isDefault: true);
        $catchAll = $this->destination('tout');
        $lessons = $this->destination('lecons');

        StoragePlacement::query()->create([
            'organization_id' => self::ORG,
            'owner_type' => null,
            'destination_id' => $catchAll->id,
        ]);
        StoragePlacement::query()->create([
            'organization_id' => self::ORG,
            'owner_type' => FakeFileOwner::TYPE,
            'destination_id' => $lessons->id,
        ]);

        $this->assertSame($lessons->id, $this->declare()->file->destination_id);
    }

    public function test_the_owner_policy_beats_every_placement(): void
    {
        $this->destination('defaut', isDefault: true);
        $place = $this->destination('place');
        $required = $this->destination('exige-par-le-module');

        StoragePlacement::query()->create([
            'organization_id' => self::ORG,
            'owner_type' => null,
            'destination_id' => $place->id,
        ]);

        FakeFileOwner::$destination = 'exige-par-le-module';

        $this->assertSame($required->id, $this->declare()->file->destination_id);
    }

    /**
     * Le point le plus discutable de la conception, et il est délibéré.
     *
     * Un repli deviné vers un fournisseur facturant le trafic sortant
     * produirait une facture qu'aucune décision n'a prise, et qui n'arriverait
     * qu'un mois plus tard.
     */
    public function test_a_named_destination_that_is_down_does_not_silently_fall_back(): void
    {
        $this->destination('defaut', isDefault: true);
        $this->destination('videos', status: Destination::READ_ONLY);

        FakeFileOwner::$destination = 'videos';

        $this->expectException(DomainException::class);

        try {
            $this->declare();
        } catch (DomainException $e) {
            $this->assertSame('STORAGE_DESTINATION_UNVERIFIED', $e->errorCode);
            $this->assertSame(0, StoredFile::query()->count());

            throw $e;
        }
    }

    public function test_a_declared_fallback_is_used_and_only_it(): void
    {
        $this->destination('defaut', isDefault: true);
        $this->destination('videos', status: Destination::READ_ONLY);
        $fallbackStore = $this->destination('archives');

        FakeFileOwner::$destination = 'videos';
        FakeFileOwner::$fallback = 'archives';

        $this->assertSame($fallbackStore->id, $this->declare()->file->destination_id);
    }

    public function test_a_fallback_that_is_also_down_fails_rather_than_going_further(): void
    {
        $default = $this->destination('defaut', isDefault: true);
        $this->destination('videos', status: Destination::READ_ONLY);
        $this->destination('archives', status: Destination::UNVERIFIED);

        FakeFileOwner::$destination = 'videos';
        FakeFileOwner::$fallback = 'archives';

        $this->expectException(DomainException::class);

        try {
            $this->declare();
        } catch (DomainException $e) {
            // Surtout pas le défaut de la plateforme : le repli n'a qu'un rang.
            $this->assertSame(0, StoredFile::query()->where('destination_id', $default->id)->count());

            throw $e;
        }
    }

    /**
     * Un fichier vit là où ses octets ont été posés. Changer une règle
     * n'affecte que les fichiers à venir — sans quoi rebrancher une
     * organisation rendrait illisibles tous ses fichiers antérieurs, d'un coup
     * et sans erreur.
     */
    public function test_changing_a_placement_never_moves_an_existing_file(): void
    {
        $first = $this->destination('premier', isDefault: true);
        $second = $this->destination('second');

        $file = $this->declare()->file;
        $this->assertSame($first->id, $file->destination_id);

        StoragePlacement::query()->create([
            'organization_id' => self::ORG,
            'owner_type' => null,
            'destination_id' => $second->id,
        ]);

        $this->assertSame($first->id, $file->fresh()->destination_id);
        $this->assertSame($second->id, $this->declare()->file->destination_id);
    }

    /**
     * Une destination d'un tiers ne sert que lui : sans ce contrôle, connaître
     * le nom d'un magasin suffirait à faire porter la facture cloud d'autrui.
     */
    public function test_someone_elses_destination_is_out_of_reach(): void
    {
        $this->destination('defaut', isDefault: true);
        $this->destination('chez-un-autre', organizationId: '019fd000-0000-7000-8000-0000000000bb');

        FakeFileOwner::$destination = 'chez-un-autre';

        $this->expectException(DomainException::class);

        try {
            $this->declare();
        } catch (DomainException $e) {
            $this->assertSame('STORAGE_DESTINATION_FORBIDDEN', $e->errorCode);

            throw $e;
        }
    }

    /**
     * Le garde-fou d'environnement, sans échappatoire : une recette pointée sur
     * le compartiment de production y écrirait sans une erreur, et le balayage
     * des orphelins y effacerait de vrais fichiers.
     */
    public function test_a_destination_of_another_environment_is_refused_at_registration(): void
    {
        $this->expectException(DomainException::class);

        try {
            app(RegisterDestination::class)->handle(
                slug: 'production',
                preset: null,
                driver: 'local',
                config: ['root' => storage_path('framework/testing/prod')],
                credentials: [],
                environment: 'production',
            );
        } catch (DomainException $e) {
            $this->assertSame('STORAGE_DESTINATION_FORBIDDEN', $e->errorCode);
            $this->assertSame(0, Destination::query()->count());

            throw $e;
        }
    }

    public function test_a_registered_destination_is_probed_and_becomes_active(): void
    {
        $destination = app(RegisterDestination::class)->handle(
            slug: 'eprouve',
            preset: null,
            driver: 'local',
            config: ['root' => storage_path('framework/testing/eprouve')],
            credentials: [],
            environment: app()->environment(),
        );

        $this->assertSame(Destination::ACTIVE, $destination->status);
        $this->assertNotNull($destination->verified_at);
    }

    /**
     * Une clé révoquée chez le fournisseur doit se savoir avant qu'un client ne
     * le découvre.
     */
    public function test_a_destination_that_stops_answering_falls_back_to_unverified_and_says_so(): void
    {
        Event::fake();

        $destination = $this->destination('cassee');

        /*
         * Le magasin devient injoignable, comme le ferait un compartiment
         * supprimé : la racine est placée **sous un fichier**, donc aucun
         * répertoire ne peut y être créé.
         *
         * Un chemin simplement absent ne suffirait pas — le pilote local le
         * créerait, et l'épreuve réussirait.
         */
        $obstacle = storage_path('framework/testing/obstacle');
        @mkdir(dirname($obstacle), 0777, true);
        file_put_contents($obstacle, 'pas un répertoire');

        $destination->forceFill(['config' => ['root' => $obstacle.'/dedans']])->save();

        $this->assertFalse(app(VerifyDestination::class)->handle($destination));

        $destination->refresh();
        $this->assertSame(Destination::UNVERIFIED, $destination->status);
        $this->assertNotNull($destination->verification_reason);

        Event::assertDispatched(
            DomainEvent::class,
            fn (DomainEvent $e): bool => $e->type === 'storage.destination.unverified'
                && $e->get('slug') === 'cassee',
        );
    }

    /**
     * `read_only` est l'état qui compte : on retire un magasin du service en
     * cessant d'y écrire, jamais en coupant la lecture.
     */
    public function test_a_read_only_destination_still_serves_what_it_holds(): void
    {
        $destination = $this->destination('sortante', isDefault: true);

        $file = $this->declare()->file;

        $destination->forceFill(['status' => Destination::READ_ONLY])->save();

        $this->assertTrue($destination->fresh()->allowsReads());
        $this->assertFalse($destination->fresh()->acceptsWrites());
        $this->assertSame($destination->id, $file->fresh()->destination_id);
    }

    /**
     * Les identifiants ne sortent jamais du modèle — ni par une sérialisation
     * distraite, ni par un journal.
     */
    public function test_credentials_never_leave_the_model(): void
    {
        $destination = $this->destination('secrets');
        $destination->forceFill(['credentials' => ['key' => 'AKIAEXAMPLE7X2Q', 'secret' => 's3cr3t']])->save();

        $this->assertArrayNotHasKey('credentials', $destination->fresh()->toArray());
        $this->assertStringContainsString('7X2Q', (string) $destination->fresh()->credentialFingerprint());
        $this->assertStringNotContainsString('s3cr3t', (string) $destination->fresh()->credentialFingerprint());
    }

    /**
     * Sans magasin, la plateforme ne peut rien stocker — et doit le dire.
     *
     * C'est le premier geste d'une mise en service, et il n'a pas de route :
     * une destination de la plateforme porte les identifiants de nos comptes et
     * sert toutes les organisations. L'exposer reviendrait a confier
     * l'infrastructure de tout le monde a qui detient un jeton d'administration.
     */
    public function test_the_platform_default_is_posed_by_hand_and_probed(): void
    {
        $this->artisan('storage:destination')->expectsOutputToContain('Aucun magasin')->assertSuccessful();

        $this->artisan('storage:destination', [
            'slug' => 'principal',
            '--driver' => 'local',
            '--root' => storage_path('framework/testing/principal'),
            '--default' => true,
        ])->assertSuccessful();

        $destination = Destination::query()->where('slug', 'principal')->firstOrFail();

        $this->assertSame(Destination::ACTIVE, $destination->status);
        $this->assertTrue($destination->is_default);
        $this->assertNotNull($destination->verified_at);
        $this->assertTrue($destination->belongsToPlatform());

        // Et il sert immediatement : c'est la seule preuve qui compte.
        $this->assertSame($destination->id, $this->declare()->file->destination_id);
    }

    /**
     * Un magasin qu'on retire du service cesse d'accepter des ecritures, et
     * continue de servir ce qu'il porte.
     */
    public function test_the_command_retires_a_store_without_cutting_reads(): void
    {
        $this->destination('sortant', isDefault: true);

        $this->artisan('storage:destination', ['slug' => 'sortant', '--status' => 'read_only'])
            ->assertSuccessful();

        $destination = Destination::query()->where('slug', 'sortant')->firstOrFail();

        $this->assertTrue($destination->allowsReads());
        $this->assertFalse($destination->acceptsWrites());
    }

    /**
     * Une epreuve ratee n'efface pas la ligne : la corriger vaut mieux que la
     * recreer, et une tentative laisserait sinon un compartiment a moitie
     * configure dont personne ne garde trace.
     */
    public function test_a_failed_probe_leaves_the_row_and_says_why(): void
    {
        $obstacle = storage_path('framework/testing/obstacle-cli');
        @mkdir(dirname($obstacle), 0777, true);
        file_put_contents($obstacle, 'pas un répertoire');

        $this->artisan('storage:destination', [
            'slug' => 'casse',
            '--driver' => 'local',
            '--root' => $obstacle.'/dedans',
        ])->assertFailed();

        $destination = Destination::query()->where('slug', 'casse')->firstOrFail();

        $this->assertSame(Destination::UNVERIFIED, $destination->status);
        $this->assertNotNull($destination->verification_reason);
    }

    /**
     * Sans shell, l'environnement est la seule interface.
     *
     * L'offre gratuite de Render n'en offre pas : sans cette voie, il
     * n'existerait aucun moyen de poser la premiere destination — ni commande,
     * ni route, et une route est precisement ce que la conception refuse.
     */
    public function test_the_default_store_can_be_posed_from_the_environment(): void
    {
        putenv('STORAGE_DEFAULT_SLUG=depuis-env');
        putenv('STORAGE_DEFAULT_DRIVER=local');
        putenv('STORAGE_DEFAULT_ROOT='.storage_path('framework/testing/depuis-env'));

        $this->artisan('storage:destination', ['--from-env' => true])->assertSuccessful();

        $destination = Destination::query()->where('slug', 'depuis-env')->firstOrFail();

        $this->assertSame(Destination::ACTIVE, $destination->status);
        $this->assertTrue($destination->is_default);

        // Idempotent : le conteneur redemarre a chaque deploiement, et a chaque
        // reveil apres sommeil.
        $this->artisan('storage:destination', ['--from-env' => true])->assertSuccessful();
        $this->assertSame(1, Destination::query()->where('slug', 'depuis-env')->count());

        putenv('STORAGE_DEFAULT_SLUG');
        putenv('STORAGE_DEFAULT_DRIVER');
        putenv('STORAGE_DEFAULT_ROOT');
    }

    /**
     * Un magasin injoignable ne doit pas empecher la plateforme de demarrer :
     * l'authentification, les paiements et les notifications n'en dependent
     * pas. L'epreuve quotidienne devient la reprise.
     */
    public function test_a_broken_store_from_the_environment_never_blocks_boot(): void
    {
        $obstacle = storage_path('framework/testing/obstacle-env');
        @mkdir(dirname($obstacle), 0777, true);
        file_put_contents($obstacle, 'pas un répertoire');

        putenv('STORAGE_DEFAULT_SLUG=casse-env');
        putenv('STORAGE_DEFAULT_DRIVER=local');
        putenv('STORAGE_DEFAULT_ROOT='.$obstacle.'/dedans');

        // Sortie nulle malgre l'echec : le conteneur doit demarrer.
        $this->artisan('storage:destination', ['--from-env' => true])->assertSuccessful();

        $this->assertSame(
            Destination::UNVERIFIED,
            Destination::query()->where('slug', 'casse-env')->firstOrFail()->status,
        );

        putenv('STORAGE_DEFAULT_SLUG');
        putenv('STORAGE_DEFAULT_DRIVER');
        putenv('STORAGE_DEFAULT_ROOT');
    }

    /**
     * Une premiere tentative ratee ne doit pas etre definitive.
     *
     * Sans cette reprise, la ligne existerait, l'amorcage passerait son chemin,
     * et corriger une variable dans le tableau de bord n'aurait aucun effet.
     * Sur une offre sans shell, c'est une impasse — et elle a ete rencontree en
     * production.
     */
    public function test_a_broken_store_is_repaired_when_the_environment_is_corrected(): void
    {
        $obstacle = storage_path('framework/testing/obstacle-reprise');
        @mkdir(dirname($obstacle), 0777, true);
        file_put_contents($obstacle, 'pas un répertoire');

        putenv('STORAGE_DEFAULT_SLUG=reprise');
        putenv('STORAGE_DEFAULT_DRIVER=local');
        putenv('STORAGE_DEFAULT_ROOT='.$obstacle.'/dedans');

        $this->artisan('storage:destination', ['--from-env' => true])->assertSuccessful();
        $this->assertSame(Destination::UNVERIFIED, Destination::query()->where('slug', 'reprise')->firstOrFail()->status);

        // L'exploitant corrige la variable, et redeploie.
        putenv('STORAGE_DEFAULT_ROOT='.storage_path('framework/testing/reprise-ok'));

        $this->artisan('storage:destination', ['--from-env' => true])->assertSuccessful();

        $destination = Destination::query()->where('slug', 'reprise')->firstOrFail();
        $this->assertSame(Destination::ACTIVE, $destination->status);
        $this->assertSame(1, Destination::query()->where('slug', 'reprise')->count());

        putenv('STORAGE_DEFAULT_SLUG');
        putenv('STORAGE_DEFAULT_DRIVER');
        putenv('STORAGE_DEFAULT_ROOT');
    }

    /**
     * En revanche, un magasin **qui sert** ne se laisse jamais reecrire par
     * l'environnement : une variable oubliee le repointerait vers un autre
     * compte, et les fichiers deja poses deviendraient introuvables sans
     * qu'aucune erreur ne le dise.
     */
    public function test_a_working_store_is_never_rewritten_by_the_environment(): void
    {
        $destination = $this->destination('intouchable', isDefault: true);
        $root = $destination->config['root'];

        putenv('STORAGE_DEFAULT_SLUG=intouchable');
        putenv('STORAGE_DEFAULT_DRIVER=local');
        putenv('STORAGE_DEFAULT_ROOT='.storage_path('framework/testing/ailleurs'));

        $this->artisan('storage:destination', ['--from-env' => true])->assertSuccessful();

        $this->assertSame($root, $destination->fresh()->config['root']);

        putenv('STORAGE_DEFAULT_SLUG');
        putenv('STORAGE_DEFAULT_DRIVER');
        putenv('STORAGE_DEFAULT_ROOT');
    }

    private function declare(): DeclaredFile
    {
        return app(DeclareFile::class)->handle(
            owner: new FileRef(FakeFileOwner::TYPE, 'lecon-1'),
            actor: FileActor::user('019fd000-0000-7000-8000-0000000000cc', self::ORG),
            name: 'cours.pdf',
            mimeType: 'application/pdf',
            size: 100,
        );
    }

    private function destination(
        string $slug,
        string $status = Destination::ACTIVE,
        bool $isDefault = false,
        ?string $organizationId = null,
    ): Destination {
        return Destination::query()->create([
            'slug' => $slug,
            'driver' => 'local',
            'config' => ['root' => storage_path('framework/testing/'.$slug)],
            'owner_organization_id' => $organizationId,
            'environment' => app()->environment(),
            'status' => $status,
            'is_default' => $isDefault,
            'verified_at' => $status === Destination::UNVERIFIED ? null : now(),
        ]);
    }
}
