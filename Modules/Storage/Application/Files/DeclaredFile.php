<?php

declare(strict_types=1);

namespace Modules\Storage\Application\Files;

use Modules\Storage\Domain\Models\StoredFile;
use Modules\Storage\Infrastructure\Drivers\UploadTicket;

/**
 * Ce que la déclaration rend : la ligne, et l'autorisation d'écriture.
 *
 * `directUpload` dit au client si les octets passeront par la plateforme. Il
 * n'a pas à en tenir compte — suivre la méthode, l'URL et les en-têtes suffit —
 * mais l'exposer permet à un client soucieux de sa bande passante de le savoir.
 */
final readonly class DeclaredFile
{
    public function __construct(
        public StoredFile $file,
        public UploadTicket $ticket,
        public bool $directUpload,
    ) {}
}
