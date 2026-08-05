<?php

declare(strict_types=1);

namespace Modules\Storage\Application\Files;

use App\Platform\Contracts\BillingContract;
use App\Platform\Events\DomainEvent;
use App\Platform\Exceptions\DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Modules\Storage\Domain\Models\Destination;

/**
 * Les octets d'une organisation, comptés ici et plafonnés ailleurs.
 *
 * Billing publie la limite, Storage compte sa ressource — la règle déjà posée
 * pour les sièges et les SMS.
 *
 * ## Ce que le quota ne compte pas
 *
 * Les octets posés sur la destination d'un client ou d'un produit externe. Ils
 * sont enregistrés, donc rapportables, mais jamais opposables : il paie sa
 * propre facture cloud, et lui opposer notre quota n'aurait aucun sens.
 *
 * @see docs/03-services/storage/01-overview.md
 */
final class StorageQuota
{
    private const KEY = 'storage_gb';

    private const GIGABYTE = 1024 * 1024 * 1024;

    /** Seuils annoncés, dans l'ordre. */
    private const THRESHOLDS = [80, 100];

    public function __construct(private readonly BillingContract $billing) {}

    public function assertHasRoom(?string $organizationId, Destination $destination, int $bytes): void
    {
        if ($organizationId === null || ! $destination->belongsToPlatform()) {
            return;
        }

        $limit = $this->billing->limit($organizationId, self::KEY);

        // Non couvert ou illimité : rien à plafonner. Une organisation sans
        // abonnement n'est pas bloquée — un quota borne un usage autorisé, il
        // ne décide pas de l'autorisation.
        if (! $limit->covered || $limit->isUnlimited()) {
            return;
        }

        $limitBytes = (int) $limit->value * self::GIGABYTE;
        $after = $this->platformBytes($organizationId) + $bytes;

        if ($after > $limitBytes) {
            throw new DomainException(
                'STORAGE_QUOTA_EXCEEDED',
                __('storage::messages.quota_exceeded'),
                429,
                ['limit' => self::KEY, 'limit_bytes' => $limitBytes, 'used_bytes' => $after - $bytes],
            );
        }
    }

    /**
     * Ajuste le compteur, et annonce un seuil s'il vient d'être franchi.
     *
     * L'événement est émis **au franchissement**, jamais à chaque écriture
     * au-delà du seuil : une organisation à 81 % produirait sinon un message
     * par fichier, et Notify livrerait fidèlement cette avalanche.
     */
    public function adjust(?string $organizationId, Destination $destination, int $bytes, int $files): void
    {
        if ($organizationId === null) {
            return;
        }

        $before = $destination->belongsToPlatform() ? $this->platformBytes($organizationId) : 0;

        /*
         * Créer la ligne à zéro, puis incrémenter — jamais l'inverse.
         *
         * Un `upsert` portant directement la valeur compterait le premier
         * fichier deux fois : une fois à l'insertion, une fois à
         * l'incrément qui suit. La ligne neutre rend les deux chemins
         * identiques.
         */
        DB::table('storage_usage')->insertOrIgnore([
            'organization_id' => $organizationId,
            'destination_id' => $destination->id,
            'bytes_used' => 0,
            'file_count' => 0,
            'updated_at' => now(),
        ]);

        DB::table('storage_usage')
            ->where('organization_id', $organizationId)
            ->where('destination_id', $destination->id)
            ->update([
                'bytes_used' => DB::raw('GREATEST(0, bytes_used + '.$bytes.')'),
                'file_count' => DB::raw('GREATEST(0, file_count + '.$files.')'),
                'updated_at' => now(),
            ]);

        if ($bytes > 0 && $destination->belongsToPlatform()) {
            $this->announceThresholds($organizationId, $before, $before + $bytes);
        }
    }

    public function platformBytes(string $organizationId): int
    {
        return (int) DB::table('storage_usage')
            ->join('storage_destinations', 'storage_destinations.id', '=', 'storage_usage.destination_id')
            ->where('storage_usage.organization_id', $organizationId)
            ->whereNull('storage_destinations.owner_organization_id')
            ->whereNull('storage_destinations.owner_api_key_id')
            ->sum('storage_usage.bytes_used');
    }

    /**
     * Le seuil à 100 % est plus utile que le refus qu'il annonce : au moment où
     * le client voit `STORAGE_QUOTA_EXCEEDED`, il est déjà bloqué.
     */
    private function announceThresholds(string $organizationId, int $before, int $after): void
    {
        $limit = $this->billing->limit($organizationId, self::KEY);

        if (! $limit->covered || $limit->isUnlimited() || $limit->value === null || $limit->value <= 0) {
            return;
        }

        $limitBytes = (int) $limit->value * self::GIGABYTE;

        foreach (self::THRESHOLDS as $threshold) {
            $mark = (int) ($limitBytes * $threshold / 100);

            if ($before < $mark && $after >= $mark) {
                Event::dispatch(new DomainEvent('storage.quota.threshold_reached', [
                    'organization_id' => $organizationId,
                    'threshold' => $threshold,
                    'bytes_used' => $after,
                    'bytes_limit' => $limitBytes,
                ], $organizationId));
            }
        }
    }
}
