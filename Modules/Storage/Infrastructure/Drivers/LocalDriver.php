<?php

declare(strict_types=1);

namespace Modules\Storage\Infrastructure\Drivers;

use DateTimeImmutable;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Modules\Storage\Domain\Models\Destination;
use Throwable;

/**
 * Le magasin du développement et des tests.
 *
 * ## Pourquoi il vaut la peine d'exister
 *
 * Il permet d'éprouver **toute** la chaîne — déclarer, écrire, confirmer, lire —
 * sans compte externe et sans réseau. C'est précisément ce qui manquait au
 * canal SMS de Notify : intégralement écrit, jamais exécuté contre une vraie
 * passerelle, et faux sur trois points le jour du premier envoi.
 *
 * ## Ce qu'il fait autrement, et il faut le savoir
 *
 * L'URL d'écriture pointe vers une route signée de la plateforme, pas vers un
 * hôte tiers : les octets **traversent** ici le processus PHP, contrairement à
 * ce que pose l'ADR-0012.
 *
 * C'est assumé, et borné à ce pilote. La forme vue par le client reste
 * identique — obtenir une URL, y écrire, confirmer — donc un client éprouvé
 * contre ce magasin fonctionne contre S3. Ce qui change est ce qui doit
 * changer : en production, l'octet ne passe pas par nous.
 */
final class LocalDriver implements StorageDriver
{
    public function capabilities(): DriverCapabilities
    {
        return new DriverCapabilities(
            directUpload: true,
            temporaryUrls: true,
            checksums: true,

            // La borne du mode mandataire, faute d'être un vrai magasin.
            maxObjectBytes: DriverCapabilities::PROXY_MAX_BYTES,
        );
    }

    public function uploadTicket(Destination $to, string $path, UploadIntent $intent): UploadTicket
    {
        $expiry = now()->addSeconds($intent->ttlSeconds);

        /*
         * Une URL signée par Laravel, hors de la pile d'authentification de
         * l'API : c'est bien une capacité, valable pour un objet et une durée,
         * et non un droit attaché à qui la présente.
         */
        $url = URL::temporarySignedRoute('storage.local-upload', $expiry, [
            'destination' => $to->id,
            'path' => base64_encode($path),
        ]);

        return new UploadTicket(
            method: 'PUT',
            url: $url,
            headers: ['Content-Type' => $intent->mimeType],
            expiresAt: new DateTimeImmutable($expiry->toIso8601String()),
        );
    }

    public function inspect(Destination $at, string $path): ?ObjectFacts
    {
        $disk = $this->disk($at);

        if (! $disk->exists($path)) {
            return null;
        }

        return new ObjectFacts(
            size: (int) $disk->size($path),
            mimeType: $this->mimeTypeOf($disk, $path),
            checksum: hash('sha256', (string) $disk->get($path)),
        );
    }

    public function readUrl(Destination $at, string $path, int $seconds): string
    {
        return URL::temporarySignedRoute('storage.local-download', now()->addSeconds($seconds), [
            'destination' => $at->id,
            'path' => base64_encode($path),
        ]);
    }

    public function delete(Destination $at, string $path): void
    {
        $this->disk($at)->delete($path);
    }

    public function put(Destination $at, string $path, string $contents, string $mimeType): void
    {
        $this->disk($at)->put($path, $contents);
    }

    public function disk(Destination $destination): Filesystem
    {
        return Storage::build([
            'driver' => 'local',
            'root' => (string) ($destination->config['root'] ?? storage_path('app/storage-module')),
            'throw' => true,
        ]);
    }

    private function mimeTypeOf(Filesystem $disk, string $path): string
    {
        try {
            $mime = $disk->mimeType($path);
        } catch (Throwable) {
            return 'application/octet-stream';
        }

        return is_string($mime) && $mime !== '' ? $mime : 'application/octet-stream';
    }
}
