<?php

declare(strict_types=1);

namespace Modules\Storage\Application\Files;

use App\Platform\Contracts\FileActor;
use App\Platform\Exceptions\DomainException;
use Modules\Storage\Domain\Models\FileDownload;
use Modules\Storage\Domain\Models\StoredFile;
use Modules\Storage\Infrastructure\Drivers\DriverRegistry;

/**
 * Une autorisation de lecture, datée.
 *
 * ## Pourquoi jamais d'URL permanente
 *
 * Une URL permanente est un droit d'accès qu'on ne peut plus retirer. Elle
 * survit au départ d'un employé, à la fin d'un abonnement, à la révocation d'un
 * partage ; elle finit dans un courriel, un cache de moteur, une capture
 * d'écran.
 *
 * Une URL courte est un droit daté. Reprendre l'accès, c'est simplement ne plus
 * en délivrer.
 *
 * @see docs/03-services/storage/03-api.md
 */
final class IssueReadUrl
{
    public function __construct(
        private readonly OwnerRegistry $owners,
        private readonly DriverRegistry $drivers,
    ) {}

    public function handle(StoredFile $file, FileActor $actor, ?string $ip = null): IssuedUrl
    {
        /*
         * Un refus de lecture rend `FILE_NOT_FOUND`, jamais `403`.
         *
         * Distinguer « inexistant » de « pas à vous » transformerait la route
         * en oracle : un client pourrait énumérer les identifiants et apprendre
         * ce qui existe chez les autres. Même règle que pour les factures et
         * les charges.
         */
        if (! $actor->mayTouch($file->owner_type)
            || ! $this->owners->for($file->owner_type)->mayRead($file->owner(), $actor)) {
            throw DomainException::notFound('FILE_NOT_FOUND', __('storage::messages.file_not_found'));
        }

        if (! $file->isReady()) {
            /*
             * Les octets ne sont pas garantis présents. Une URL signée vers un
             * objet absent rendrait une erreur du magasin — illisible, hors de
             * notre catalogue, et impossible à distinguer d'une panne.
             */
            throw DomainException::conflict('FILE_NOT_READY', __('storage::messages.file_not_ready'));
        }

        $destination = $file->destination;

        if (! $destination->allowsReads()) {
            throw new DomainException(
                'STORAGE_DESTINATION_UNAVAILABLE',
                __('storage::messages.destination_unreadable'),
                503,
            );
        }

        $seconds = (int) config('storage.read_ttl', 600);
        $url = $this->drivers->for($destination)->readUrl($destination, (string) $file->path, $seconds);
        $expiresAt = now()->addSeconds($seconds);

        // On enregistre la **délivrance**, pas l'accès : les octets sont
        // récupérés auprès du magasin, sans passer par nous.
        FileDownload::query()->create([
            'file_id' => $file->id,
            'actor_type' => $actor->type,
            'actor_id' => $actor->id,
            'ip' => $ip,
            'expires_at' => $expiresAt,
        ]);

        return new IssuedUrl($url, $expiresAt, $this->disposition($file));
    }

    /**
     * Tout ce qui n'est pas dans la liste close est servi en pièce jointe : le
     * navigateur télécharge, il n'interprète pas.
     *
     * L'URL pointant vers l'hôte du magasin, un HTML téléversé par un client ne
     * peut de toute façon rien atteindre chez nous. Rien n'oblige pour autant à
     * le rendre commode.
     */
    private function disposition(StoredFile $file): string
    {
        $inline = (array) config('storage.inline_mime_types', []);

        return in_array($file->mime_type, $inline, true) ? 'inline' : 'attachment';
    }
}
