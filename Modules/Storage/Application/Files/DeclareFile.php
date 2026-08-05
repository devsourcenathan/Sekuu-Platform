<?php

declare(strict_types=1);

namespace Modules\Storage\Application\Files;

use App\Platform\Contracts\FileActor;
use App\Platform\Contracts\FilePolicy;
use App\Platform\Contracts\FileRef;
use App\Platform\Exceptions\DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Storage\Application\Destinations\ResolveDestination;
use Modules\Storage\Domain\Models\Destination;
use Modules\Storage\Domain\Models\StoredFile;
use Modules\Storage\Infrastructure\Drivers\DriverRegistry;
use Modules\Storage\Infrastructure\Drivers\UploadIntent;

/**
 * Premier temps : déclarer.
 *
 * Rend un identifiant de fichier et une autorisation d'écriture. **Aucun octet
 * n'a encore bougé**, et rien de ce que le client annonce ne fait foi — ces
 * valeurs servent à signer une autorisation étroite et à refuser tôt ce qui
 * sera de toute façon refusé.
 *
 * @see docs/03-services/storage/03-api.md
 */
final class DeclareFile
{
    public function __construct(
        private readonly OwnerRegistry $owners,
        private readonly ResolveDestination $resolver,
        private readonly DriverRegistry $drivers,
        private readonly StorageQuota $quota,
    ) {}

    public function handle(
        FileRef $owner,
        FileActor $actor,
        string $name,
        string $mimeType,
        int $size,
        ?string $destination = null,
        ?int $retainDays = null,
    ): DeclaredFile {
        if (! $actor->mayTouch($owner->type)) {
            throw DomainException::forbidden(
                'FILE_ATTACH_FORBIDDEN',
                __('storage::messages.owner_type_out_of_scope', ['type' => $owner->type]),
            );
        }

        // Le propriétaire répond « qui, quoi, combien » en un seul aller, et
        // sans effet de bord.
        $policy = $this->owners->for($owner->type)->policy($owner, $actor);

        if (! $policy->allowed) {
            throw DomainException::forbidden(
                $policy->refusalCode ?? 'FILE_ATTACH_FORBIDDEN',
                $policy->refusalMessage ?? __('storage::messages.attach_forbidden'),
            );
        }

        // Un `fallback` sans `destination` ne se substituerait à rien : c'est
        // une politique qui paraît poser un repli et n'en pose aucun.
        if (! $policy->isCoherent()) {
            throw DomainException::unprocessable(
                'FILE_POLICY_INCOHERENT',
                __('storage::messages.policy_incoherent', ['type' => $owner->type]),
            );
        }

        $target = $this->resolver->handle($policy, $owner->type, $actor->organizationId, $actor, $destination);
        $driver = $this->drivers->for($target);
        $capabilities = $driver->capabilities();

        $this->guardMimeType($policy, $mimeType);
        $this->guardSize($policy, $capabilities->maxObjectBytes, $size, $target);
        $this->quota->assertHasRoom($actor->organizationId, $target, $size);

        $retainUntil = $this->retention($policy, $actor, $retainDays);

        $file = DB::transaction(function () use ($owner, $actor, $name, $target, $retainUntil): StoredFile {
            $id = (string) Str::uuid7();

            return StoredFile::query()->create([
                'id' => $id,
                'organization_id' => $actor->organizationId,
                'owner_type' => $owner->type,
                'owner_id' => $owner->id,
                'destination_id' => $target->id,
                'path' => $this->path($target, $actor->organizationId, $id, $name),
                'name' => $name,
                'status' => StoredFile::PENDING,
                'retain_until' => $retainUntil,
                'uploaded_by' => $actor->type === FileActor::USER ? $actor->id : null,
            ]);
        });

        $ticket = $driver->uploadTicket($target, (string) $file->path, new UploadIntent(
            mimeType: $mimeType,
            size: $size,
            ttlSeconds: (int) config('storage.upload_ttl', 900),
        ));

        return new DeclaredFile($file, $ticket, $capabilities->directUpload);
    }

    private function guardMimeType(FilePolicy $policy, string $mimeType): void
    {
        if (! $policy->acceptsMimeType($mimeType)) {
            throw DomainException::unprocessable(
                'MIME_TYPE_NOT_ALLOWED',
                __('storage::messages.mime_not_allowed', ['type' => $mimeType]),
            );
        }
    }

    /**
     * Deux bornes, et le message doit dire laquelle a parlé.
     *
     * Un client refusé par la borne du magasin — parce que sa destination ne
     * sait pas faire de téléversement direct, par exemple — chercherait
     * longtemps l'erreur du côté de sa politique.
     */
    private function guardSize(FilePolicy $policy, int $driverMax, int $size, Destination $target): void
    {
        if ($size <= 0) {
            throw DomainException::unprocessable(
                'FILE_TOO_LARGE',
                __('storage::messages.size_required'),
            );
        }

        if (! $policy->acceptsSize($size)) {
            throw DomainException::unprocessable(
                'FILE_TOO_LARGE',
                __('storage::messages.too_large_for_owner', ['max' => (string) $policy->maxBytes]),
            );
        }

        if ($size > $driverMax) {
            throw DomainException::unprocessable(
                'FILE_TOO_LARGE',
                __('storage::messages.too_large_for_destination', [
                    'destination' => (string) $target->slug,
                    'max' => (string) $driverMax,
                ]),
            );
        }
    }

    /**
     * La rétention demandée par un acteur externe est plafonnée par sa clé, et
     * ce plafond vaut zéro à l'émission.
     *
     * Jamais de rabotage silencieux à la valeur maximale : un produit qui croit
     * avoir dix ans et en obtient un ne s'en apercevrait qu'au moment où le
     * document manque.
     *
     * Celle que pose le **propriétaire** de l'objet n'est pas plafonnée : c'est
     * une obligation légale qu'il porte, pas une demande d'un appelant.
     */
    private function retention(FilePolicy $policy, FileActor $actor, ?int $requestedDays): ?Carbon
    {
        if ($policy->retainDays !== null) {
            return now()->addDays($policy->retainDays);
        }

        if ($requestedDays === null || $requestedDays <= 0) {
            return null;
        }

        $ceiling = $actor->maxRetentionDays;

        if ($ceiling !== null && $requestedDays > $ceiling) {
            throw DomainException::unprocessable(
                'FILE_RETENTION_TOO_LONG',
                __('storage::messages.retention_too_long', ['max' => (string) $ceiling]),
            );
        }

        return now()->addDays($requestedDays);
    }

    /**
     * `{prefix}{organisation}/{aaaa}/{mm}/{uuid}.{extension}`
     *
     * Le préfixe d'organisation permet un audit, un export ou une purge par
     * organisation directement sur le magasin, sans base de données. Le mois
     * évite un préfixe à un million d'objets — S3 partitionne par préfixe, et
     * un préfixe unique finit par brider les écritures.
     *
     * L'extension est dérivée du nom, mais **assainie** : un `facture.pdf.exe`
     * ne devient pas un `.exe`, et un nom sans extension n'en invente pas.
     */
    private function path(Destination $destination, ?string $organizationId, string $id, string $name): string
    {
        $extension = mb_strtolower((string) pathinfo($name, PATHINFO_EXTENSION));
        $extension = preg_replace('/[^a-z0-9]/', '', $extension) ?? '';
        $extension = mb_substr($extension, 0, 12);

        return $destination->prefix()
            .($organizationId ?? 'platform').'/'
            .now()->format('Y/m').'/'
            .$id
            .($extension === '' ? '' : '.'.$extension);
    }
}
