<?php

declare(strict_types=1);

namespace Modules\Storage\Infrastructure\Drivers;

use DateTimeImmutable;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Modules\Storage\Domain\Models\Destination;
use Throwable;

/**
 * Le pilote qui couvre presque tout.
 *
 * AWS, Cloudflare R2, Backblaze B2, Scaleway, Wasabi, MinIO : même protocole,
 * et rien de plus qu'un point d'accès, une région et un style de chemin de
 * différence. C'est ce qui permet d'ajouter un fournisseur par une entrée de
 * `config('storage.presets')`, sans toucher à cette classe.
 *
 * @see docs/03-services/storage/06-destinations.md
 */
final class S3Driver implements StorageDriver
{
    public function capabilities(): DriverCapabilities
    {
        return new DriverCapabilities(
            directUpload: true,
            temporaryUrls: true,

            /*
             * L'ETag de S3 n'est pas un MD5 quand l'objet a été écrit en
             * plusieurs parties, et certains fournisseurs ne rendent rien.
             *
             * On l'annonce disponible parce qu'il l'est le plus souvent : ce
             * qu'on obtient est conservé pour vérifier une intégrité, jamais
             * pour décider d'une issue. Aucune règle de la plateforme n'en
             * dépend, et c'est délibéré.
             */
            checksums: true,

            // 5 Gio : la borne d'un `PUT` unique chez S3. Au-delà il faudrait
            // le téléversement en plusieurs parties, qui n'est pas écrit —
            // voir ADR-0012.
            maxObjectBytes: 5 * 1024 * 1024 * 1024,
        );
    }

    public function uploadTicket(Destination $to, string $path, UploadIntent $intent): UploadTicket
    {
        $expiry = now()->addSeconds($intent->ttlSeconds);

        /** @var array{url: string, headers: array<string, string>} $signed */
        $signed = $this->disk($to)->temporaryUploadUrl($path, $expiry);

        return new UploadTicket(
            method: 'PUT',
            url: $signed['url'],

            // Le `Content-Type` est couvert par la signature : un client qui
            // écrit sous un autre type est refusé par le magasin lui-même.
            headers: $signed['headers'] + ['Content-Type' => $intent->mimeType],

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
            checksum: $this->checksumOf($disk, $path),
        );
    }

    public function readUrl(Destination $at, string $path, int $seconds): string
    {
        return $this->disk($at)->temporaryUrl($path, now()->addSeconds($seconds));
    }

    public function delete(Destination $at, string $path): void
    {
        $this->disk($at)->delete($path);
    }

    public function put(Destination $at, string $path, string $contents, string $mimeType): void
    {
        $this->disk($at)->put($path, $contents, ['ContentType' => $mimeType]);
    }

    /**
     * Un disque construit à la demande, à partir de la ligne de destination.
     *
     * Jamais `config('filesystems.disks')` : ce fichier ne décrit plus que le
     * magasin local des tests. Les identifiants viennent de la base, déchiffrés
     * par le modèle.
     */
    private function disk(Destination $destination): Filesystem
    {
        $config = $destination->config;
        $credentials = $destination->credentials ?? [];

        return Storage::build([
            'driver' => 's3',
            'key' => $credentials['key'] ?? null,
            'secret' => $credentials['secret'] ?? null,
            'token' => $credentials['token'] ?? null,
            'region' => $config['region'] ?? 'auto',
            'bucket' => $config['bucket'] ?? null,
            'endpoint' => $config['endpoint'] ?? null,
            'use_path_style_endpoint' => (bool) ($config['path_style'] ?? false),

            /*
             * `throw` à vrai : un magasin qui échoue doit le dire.
             *
             * Le défaut de Laravel avale les erreurs et rend `false`, ce qui
             * transformerait un compartiment inaccessible en « objet absent ».
             * La confirmation conclurait alors « jamais téléversé » là où le
             * vrai diagnostic est « identifiants refusés » — et le balayage
             * effacerait la déclaration d'un fichier bien présent.
             */
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

    /**
     * L'absence d'empreinte n'est jamais une erreur : tout fournisseur n'en
     * rend pas, et rien dans la plateforme n'en dépend.
     */
    private function checksumOf(Filesystem $disk, string $path): ?string
    {
        try {
            $checksum = $disk->checksum($path);
        } catch (Throwable) {
            return null;
        }

        return is_string($checksum) && $checksum !== '' ? mb_substr($checksum, 0, 64) : null;
    }
}
