<?php

declare(strict_types=1);

namespace Modules\Storage\Application\Destinations;

use App\Platform\Events\DomainEvent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Modules\Storage\Domain\Models\Destination;
use Modules\Storage\Infrastructure\Drivers\DriverRegistry;
use Throwable;

/**
 * L'épreuve : écrire un objet témoin, le relire, comparer, l'effacer.
 *
 * ## Pourquoi une destination non éprouvée ne sert jamais
 *
 * Des identifiants faux découverts au premier téléversement d'un client sont un
 * incident ; découverts à l'enregistrement, ce sont deux minutes de
 * configuration.
 *
 * Le déploiement de la plateforme a livré cinq défauts de ce genre — du code
 * qui marche là où il a été écrit. Une épreuve à l'enregistrement est le remède
 * direct, et la rejouer chaque jour attrape ce qui se casse après coup.
 *
 * @see docs/03-services/storage/06-destinations.md
 */
final class VerifyDestination
{
    public const CREDENTIALS_REJECTED = 'credentials_rejected';

    public const BUCKET_MISSING = 'bucket_missing';

    public const WRITE_DENIED = 'write_denied';

    public const UNREACHABLE = 'unreachable';

    /** Écrit puis relu, et les octets ne correspondent pas. */
    public const PROBE_MISMATCH = 'probe_mismatch';

    /**
     * La faute vient de **nous**, pas du fournisseur.
     *
     * Une dépendance absente, un pilote mal écrit. Cette catégorie existe parce
     * que son absence a coûté un déploiement : un adaptateur Flysystem manquant
     * a été rangé dans `unreachable`, et le diagnostic est parti chercher du
     * côté du réseau et des identifiants — c'est-à-dire partout sauf là où
     * était le défaut.
     *
     * Un magasin injoignable se corrige dans un tableau de bord ; celui-ci se
     * corrige dans le dépôt. Les confondre fait perdre des heures.
     */
    public const INTERNAL_ERROR = 'internal_error';

    public function __construct(private readonly DriverRegistry $drivers) {}

    public function handle(Destination $destination): bool
    {
        $etaitActive = $destination->status === Destination::ACTIVE;
        $temoin = $destination->prefix().'.sekuu-probe';
        $contenu = 'sekuu-probe-'.Str::uuid()->toString();

        try {
            $driver = $this->drivers->for($destination);
            $driver->put($destination, $temoin, $contenu, 'text/plain');

            $faits = $driver->inspect($destination, $temoin);

            if ($faits === null || $faits->size !== strlen($contenu)) {
                return $this->fail($destination, self::PROBE_MISMATCH, 'Objet écrit puis introuvable ou de taille inattendue.', $etaitActive);
            }

            $driver->delete($destination, $temoin);
        } catch (Throwable $e) {
            return $this->fail($destination, $this->classify($e), $e->getMessage(), $etaitActive);
        }

        $destination->forceFill([
            'status' => $destination->status === Destination::DISABLED ? Destination::DISABLED : Destination::ACTIVE,
            'verified_at' => now(),
            'verification_reason' => null,
            'verification_error' => null,
        ])->save();

        return true;
    }

    /**
     * Une destination `read_only` ou `disabled` reste dans son état : ce sont
     * des décisions humaines, et l'épreuve n'a pas à les défaire.
     *
     * Seule une destination qui **servait** bascule en `unverified`, et c'est
     * ce basculement qui produit l'événement.
     */
    private function fail(Destination $destination, string $reason, string $error, bool $etaitActive): bool
    {
        $destination->forceFill([
            'status' => in_array($destination->status, [Destination::READ_ONLY, Destination::DISABLED], true)
                ? $destination->status
                : Destination::UNVERIFIED,
            'verification_reason' => $reason,

            // Le message brut est conservé en base pour un opérateur, jamais
            // publié : une erreur S3 peut porter un identifiant de compte, un
            // ARN, un nom de rôle — de l'infrastructure d'un tiers.
            'verification_error' => mb_substr($error, 0, 2000),
        ])->save();

        if ($etaitActive) {
            Event::dispatch(new DomainEvent('storage.destination.unverified', [
                'destination_id' => (string) $destination->id,
                'slug' => (string) $destination->slug,
                'reason' => $reason,
                'since' => now()->toIso8601String(),
            ]));
        }

        return false;
    }

    /**
     * Un jeu fermé de raisons, pour qu'un consommateur puisse réagir
     * différemment selon le cas — les trois premières demandent une action
     * humaine, `unreachable` se résout souvent seule.
     */
    private function classify(Throwable $e): string
    {
        /*
         * Une `Error` n'est pas une erreur d'exploitation : c'est une classe
         * absente, un type incompatible, un appel impossible. Aucun réglage de
         * fournisseur n'y changera rien.
         */
        if ($e instanceof \Error) {
            return self::INTERNAL_ERROR;
        }

        $message = mb_strtolower($e->getMessage());

        return match (true) {
            str_contains($message, 'invalidaccesskeyid'),
            str_contains($message, 'signaturedoesnotmatch'),
            str_contains($message, '403'),
            str_contains($message, 'forbidden') => self::CREDENTIALS_REJECTED,

            str_contains($message, 'nosuchbucket'),
            str_contains($message, 'bucket') && str_contains($message, 'not') => self::BUCKET_MISSING,

            str_contains($message, 'accessdenied'),
            str_contains($message, 'permission') => self::WRITE_DENIED,

            default => self::UNREACHABLE,
        };
    }
}
