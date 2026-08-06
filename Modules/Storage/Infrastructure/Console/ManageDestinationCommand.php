<?php

declare(strict_types=1);

namespace Modules\Storage\Infrastructure\Console;

use App\Platform\Exceptions\DomainException;
use Illuminate\Console\Command;
use Modules\Storage\Application\Destinations\RegisterDestination;
use Modules\Storage\Domain\Models\Destination;
use Modules\Storage\Domain\Models\StoredFile;

/**
 * Poser un magasin de la plateforme.
 *
 * ## Pourquoi ce n'est pas une route
 *
 * L'API n'administre que les destinations **d'un client** : `find()` exige que
 * l'appelant en soit propriétaire. Celles de la plateforme portent les
 * identifiants de nos propres comptes cloud et servent toutes les
 * organisations — les exposer à une route reviendrait à confier l'infrastructure
 * de facturation de tout le monde à qui détient un jeton d'administration.
 *
 * Elles se posent donc à la main, par quelqu'un qui a accès au serveur. C'est la
 * même logique que `payments:endpoint`.
 *
 * @see docs/03-services/storage/06-destinations.md
 */
final class ManageDestinationCommand extends Command
{
    protected $signature = 'storage:destination
        {slug? : Nom court du magasin}
        {--preset= : aws, r2, b2, scaleway, minio}
        {--driver= : Pilote, si aucun prereglage}
        {--bucket= : Compartiment}
        {--region= : Region}
        {--account-id= : Identifiant de compte (R2)}
        {--endpoint= : Point d acces, si aucun prereglage}
        {--prefix= : Prefixe de cle}
        {--root= : Racine, pour le pilote local}
        {--key= : Identifiant, sinon demande sans echo}
        {--secret= : Secret, sinon demande sans echo}
        {--default : En faire le magasin par defaut de cet environnement}
        {--status= : active, read_only ou disabled, sur un magasin existant}
        {--from-env : Pose le magasin par defaut decrit par STORAGE_DEFAULT_*}';

    protected $description = 'Liste les magasins de la plateforme, en pose un, ou en retire un du service.';

    public function handle(RegisterDestination $register): int
    {
        if ($this->option('from-env')) {
            return $this->fromEnvironment($register);
        }

        $slug = $this->argument('slug');

        if ($slug === null) {
            return $this->list();
        }

        $existing = Destination::query()->where('slug', $slug)->first();

        if ($existing !== null) {
            return $this->changeStatus($existing);
        }

        return $this->create($register, (string) $slug);
    }

    /**
     * Poser le magasin par défaut depuis l'environnement, au démarrage.
     *
     * ## Pourquoi cette voie existe
     *
     * L'offre gratuite de Render n'a **pas de shell**. Sans elle, il n'existe
     * aucun moyen de créer la première destination : ni ligne de commande, ni
     * route — et une route serait précisément ce que §5.1 de la mise en service
     * refuse.
     *
     * C'est le même geste, exécuté par la seule interface dont dispose
     * l'exploitant. Les identifiants passent par les variables d'environnement,
     * là où reposent déjà ceux des agrégateurs de paiement.
     *
     * ## Elle ne fait jamais échouer un démarrage
     *
     * Un magasin injoignable ne doit pas empêcher la plateforme de répondre :
     * les paiements, l'authentification et les notifications n'en dépendent
     * pas. La ligne reste `unverified`, et **l'épreuve quotidienne devient la
     * reprise** — la destination s'activera d'elle-même le jour où les
     * identifiants seront corrigés, sans nouveau déploiement.
     */
    private function fromEnvironment(RegisterDestination $register): int
    {
        $slug = (string) env('STORAGE_DEFAULT_SLUG', '');

        if ($slug === '') {
            $this->line('[storage] aucun magasin déclaré dans l\'environnement.');

            return self::SUCCESS;
        }

        $existing = Destination::query()->where('slug', $slug)->first();

        /*
         * Un magasin **qui sert** ne se laisse pas réécrire par
         * l'environnement : une variable oubliée le repointerait vers un autre
         * compte, et les fichiers déjà posés deviendraient introuvables sans
         * qu'aucune erreur ne le dise.
         *
         * Le cas courant, aussi : le conteneur redémarre à chaque déploiement
         * et à chaque réveil après sommeil.
         */
        if ($existing !== null && $existing->status !== Destination::UNVERIFIED) {
            $this->line("[storage] magasin « {$slug} » déjà posé.");

            return self::SUCCESS;
        }

        $config = array_filter([
            'bucket' => env('STORAGE_DEFAULT_BUCKET'),
            'region' => env('STORAGE_DEFAULT_REGION'),
            'account_id' => env('STORAGE_DEFAULT_ACCOUNT_ID'),
            'endpoint' => env('STORAGE_DEFAULT_ENDPOINT'),
            'prefix' => env('STORAGE_DEFAULT_PREFIX'),
            'root' => env('STORAGE_DEFAULT_ROOT'),
        ], fn ($v): bool => $v !== null && $v !== '');

        $key = (string) env('STORAGE_DEFAULT_KEY', '');
        $credentials = $key === ''
            ? []
            : ['key' => $key, 'secret' => (string) env('STORAGE_DEFAULT_SECRET', '')];

        try {
            /*
             * Un magasin jamais éprouvé est **repris** avec la configuration
             * courante, puis remis à l'épreuve.
             *
             * Sans cela, une première tentative ratée serait définitive là où il
             * n'y a pas de shell : la ligne existerait, l'amorçage passerait son
             * chemin, et corriger une variable dans le tableau de bord n'aurait
             * aucun effet. C'est une impasse, et elle a été rencontrée.
             */
            $destination = $existing !== null
                ? $register->repair(
                    destination: $existing,
                    preset: env('STORAGE_DEFAULT_PRESET'),
                    driver: env('STORAGE_DEFAULT_DRIVER'),
                    config: $config,
                    credentials: $credentials,
                )
                : $register->handle(
                    slug: $slug,
                    preset: env('STORAGE_DEFAULT_PRESET'),
                    driver: env('STORAGE_DEFAULT_DRIVER'),
                    config: $config,
                    credentials: $credentials,
                    environment: app()->environment(),
                    isDefault: true,
                );
        } catch (DomainException $e) {
            $this->error("[storage] magasin « {$slug} » non posé : {$e->getMessage()}");

            return self::SUCCESS;
        }

        if ($destination->status === Destination::ACTIVE) {
            $this->line("[storage] magasin « {$slug} » posé et éprouvé.");

            return self::SUCCESS;
        }

        $this->error("[storage] magasin « {$slug} » posé mais NON ÉPREUVÉ — {$destination->verification_reason}.");

        /*
         * Le message brut du fournisseur, ici et nulle part ailleurs.
         *
         * Il est tenu hors des événements et des réponses d'API — une erreur S3
         * peut porter un identifiant de compte, un ARN, un nom de rôle. Mais le
         * journal de démarrage n'est lisible que par l'exploitant, qui est
         * précisément le propriétaire de ce compte.
         *
         * Sans cette ligne, le diagnostic ne vit qu'en base, c'est-à-dire hors
         * de portée sur une offre sans shell — la seule où ce chemin sert.
         */
        foreach (preg_split('/\R/', (string) $destination->verification_error) ?: [] as $row) {
            if (trim($row) !== '') {
                $this->line('[storage]   '.mb_substr(trim($row), 0, 300));
            }
        }

        $this->line('[storage] aucun fichier ne sera déposé. L\'épreuve est rejouée chaque nuit,');
        $this->line('[storage] et la destination s\'activera d\'elle-même une fois corrigée.');

        return self::SUCCESS;
    }

    private function list(): int
    {
        $destinations = Destination::query()->orderBy('slug')->get();

        if ($destinations->isEmpty()) {
            $this->warn('Aucun magasin. Aucun fichier ne peut être déposé.');
            $this->newLine();
            $this->comment('Poser le magasin par défaut :');
            $this->comment('  php artisan storage:destination principal --preset=r2 --bucket=… --account-id=… --default');

            return self::SUCCESS;
        }

        $this->table(
            ['Nom', 'Pilote', 'Environnement', 'État', 'Défaut', 'Appartient à', 'Fichiers'],
            $destinations->map(fn (Destination $d): array => [
                $d->slug,
                $d->preset ?? $d->driver,
                $d->environment,
                $d->status,
                $d->is_default ? 'oui' : '',
                $d->belongsToPlatform() ? 'la plateforme' : 'un client',
                StoredFile::query()->where('destination_id', $d->id)->where('status', '<>', StoredFile::DELETED)->count(),
            ])->all(),
        );

        return self::SUCCESS;
    }

    private function create(RegisterDestination $register, string $slug): int
    {
        $preset = $this->option('preset');
        $driver = $this->option('driver');

        if ($preset === null && $driver === null) {
            $this->error('Un préréglage (--preset) ou un pilote (--driver) est requis.');

            return self::FAILURE;
        }

        $config = array_filter([
            'bucket' => $this->option('bucket'),
            'region' => $this->option('region'),
            'account_id' => $this->option('account-id'),
            'endpoint' => $this->option('endpoint'),
            'prefix' => $this->option('prefix'),
            'root' => $this->option('root'),
        ], fn ($v): bool => $v !== null && $v !== '');

        try {
            $destination = $register->handle(
                slug: $slug,
                preset: $preset,
                driver: $driver,
                config: $config,
                credentials: $this->credentials($driver ?? 's3'),
                environment: app()->environment(),
                isDefault: (bool) $this->option('default'),
            );
        } catch (DomainException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($destination->status === Destination::ACTIVE) {
            $this->info("Magasin « {$destination->slug} » posé et éprouvé : écriture, relecture, effacement.");

            return self::SUCCESS;
        }

        /*
         * L'échec de l'épreuve n'efface pas la ligne : la corriger vaut mieux
         * que la recréer, et une tentative ratée ne doit pas laisser un
         * compartiment à moitié configuré dont personne ne garde trace.
         */
        $this->error("Épreuve échouée — {$destination->verification_reason}.");
        $this->line((string) $destination->verification_error);
        $this->newLine();
        $this->comment('Le magasin existe mais ne sert personne. Corrigez, puis : php artisan storage:verify '.$slug);

        return self::FAILURE;
    }

    private function changeStatus(Destination $destination): int
    {
        $status = $this->option('status');

        if ($status === null) {
            $this->error("Le magasin « {$destination->slug} » existe déjà. Utilisez --status pour le changer d'état.");

            return self::FAILURE;
        }

        if (! in_array($status, [Destination::ACTIVE, Destination::READ_ONLY, Destination::DISABLED], true)) {
            $this->error('État inconnu. Attendu : active, read_only ou disabled.');

            return self::FAILURE;
        }

        if ($status === Destination::ACTIVE && $destination->verified_at === null) {
            $this->error("Ce magasin n'a jamais réussi l'épreuve : il ne peut pas être activé.");

            return self::FAILURE;
        }

        $destination->forceFill(['status' => $status])->save();

        $this->info("Magasin « {$destination->slug} » : {$status}.");

        if ($status === Destination::READ_ONLY) {
            $this->comment("On cesse d'y écrire ; les fichiers qu'il porte restent lisibles.");
        }

        if ($status === Destination::DISABLED) {
            $this->warn('Les fichiers de ce magasin ne sont plus servables. À réserver au cas où le compte n\'est plus le nôtre.');
        }

        return self::SUCCESS;
    }

    /**
     * Demandés sans écho quand ils ne sont pas donnés en option.
     *
     * Une clé passée en argument entre dans l'historique du shell, et y reste
     * bien après que le terminal soit fermé — sur un serveur partagé, c'est une
     * fuite qui ne se voit pas.
     *
     * @return array<string, string>
     */
    private function credentials(string $driver): array
    {
        // Le magasin local n'a pas d'identifiants, et en demander donnerait
        // l'impression qu'il en faut.
        if ($driver === 'local') {
            return [];
        }

        $key = $this->option('key') ?? $this->secret('Identifiant (laisser vide pour aucun)');

        if ($key === null || $key === '') {
            return [];
        }

        $secret = $this->option('secret') ?? $this->secret('Secret');

        return ['key' => (string) $key, 'secret' => (string) $secret];
    }
}
