<?php

declare(strict_types=1);

namespace Modules\Billing\Application\Invoicing;

use App\Platform\Contracts\AttachedFile;
use App\Platform\Contracts\FileActor;
use App\Platform\Contracts\FileOwner;
use App\Platform\Contracts\FilePolicy;
use App\Platform\Contracts\FileRef;
use Modules\Billing\Domain\Models\Invoice;

/**
 * Billing, propriétaire des fichiers de ses factures.
 *
 * Le pendant exact d'{@see InvoicePayable} : là-bas Billing dit ce qu'une
 * facture vaut, ici il dit qui peut la lire.
 *
 * @see docs/04-decisions/adr-0013-invoice-pdf-frozen.md
 */
final class InvoiceFiles implements FileOwner
{
    public const TYPE = 'billing.invoice';

    /** Dix ans : la durée de conservation des pièces comptables au Cameroun. */
    public const RETENTION_DAYS = 3653;

    /**
     * Seule la plateforme attache un PDF à une facture.
     *
     * Un utilisateur, fût-il propriétaire de l'organisation, n'a rien à
     * téléverser ici : un document déposé par un client sur sa propre facture
     * serait indiscernable de celui que nous avons émis, et porterait la même
     * rétention de dix ans.
     */
    public function policy(FileRef $ref, FileActor $actor): FilePolicy
    {
        if (! $actor->isSystem() || $this->invoice($ref) === null) {
            return FilePolicy::refuse();
        }

        return FilePolicy::allow(
            mimeTypes: ['application/pdf'],
            maxBytes: 8 * 1024 * 1024,
            retainDays: self::RETENTION_DAYS,
        );
    }

    /**
     * La facture doit appartenir à l'organisation de l'acteur.
     *
     * Un refus rendra `FILE_NOT_FOUND` : c'est déjà la règle de la route de
     * consultation d'une facture, et la garder identique évite qu'un même objet
     * réponde différemment selon la porte empruntée.
     */
    public function mayRead(FileRef $ref, FileActor $actor): bool
    {
        $invoice = $this->invoice($ref);

        if ($invoice === null) {
            return false;
        }

        return $actor->isSystem() || $invoice->organization_id === $actor->organizationId;
    }

    /**
     * Le PDF est prêt : la facture le porte.
     *
     * Appelée dans la transaction de confirmation. Si un PDF avait déjà été
     * attaché, la référence bascule sur le nouveau — l'ancien **demeure**, avec
     * sa rétention. Le document envoyé au client reste consultable, et c'est
     * exactement ce que l'ADR-0013 refuse de perdre.
     */
    public function attached(AttachedFile $file): void
    {
        $invoice = $this->invoice($file->owner);

        $invoice?->forceFill([
            'pdf_file_id' => $file->fileId,
            'pdf_rendered_at' => now(),
        ])->save();
    }

    /**
     * Un PDF de facture sous rétention ne peut pas être supprimé, donc ceci
     * n'arrive qu'après dix ans — ou sur un fichier qui n'a jamais été
     * confirmé.
     */
    public function detached(AttachedFile $file): void
    {
        $invoice = $this->invoice($file->owner);

        if ($invoice?->pdf_file_id === $file->fileId) {
            $invoice->forceFill(['pdf_file_id' => null, 'pdf_rendered_at' => null])->save();
        }
    }

    private function invoice(FileRef $ref): ?Invoice
    {
        return Invoice::query()->find($ref->id);
    }
}
