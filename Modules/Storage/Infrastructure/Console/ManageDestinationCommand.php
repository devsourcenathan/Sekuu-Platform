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
        {--status= : active, read_only ou disabled, sur un magasin existant}';

    protected $description = 'Liste les magasins de la plateforme, en pose un, ou en retire un du service.';

    public function handle(RegisterDestination $register): int
    {
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
