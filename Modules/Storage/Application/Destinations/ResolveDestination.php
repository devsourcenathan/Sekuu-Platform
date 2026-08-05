<?php

declare(strict_types=1);

namespace Modules\Storage\Application\Destinations;

use App\Platform\Contracts\FileActor;
use App\Platform\Contracts\FilePolicy;
use App\Platform\Exceptions\DomainException;
use Illuminate\Support\Facades\Log;
use Modules\Storage\Domain\Models\Destination;
use Modules\Storage\Domain\Models\StoragePlacement;

/**
 * Où poser les octets.
 *
 * Du plus précis au plus général, premier trouvé :
 *
 *  1. la destination nommée par le propriétaire de l'objet dans sa politique ;
 *  2. une règle de placement de l'organisation pour cet `owner_type` ;
 *  3. une règle de placement de l'organisation, tous types ;
 *  4. la destination par défaut de la plateforme.
 *
 * Le résultat est écrit sur la ligne du fichier et **jamais recalculé** : un
 * fichier vit là où ses octets ont été posés.
 *
 * @see docs/03-services/storage/06-destinations.md
 */
final class ResolveDestination
{
    public function handle(
        FilePolicy $policy,
        string $ownerType,
        ?string $organizationId,
        FileActor $actor,
        ?string $requested = null,
    ): Destination {
        /*
         * Une destination explicitement demandée — par l'appelant externe ou
         * par la politique du propriétaire — n'autorise aucun repli implicite.
         *
         * Un module qui exige R2 pour ses vidéos a une raison presque toujours
         * économique. Un repli deviné vers un fournisseur facturant le trafic
         * sortant produirait une facture qu'aucune décision n'a prise, et qui
         * n'arriverait qu'un mois plus tard.
         */
        $named = $requested ?? $policy->destination;

        if ($named !== null) {
            return $this->named($named, $policy->fallback, $actor);
        }

        if ($organizationId !== null) {
            $placed = $this->fromPlacements($organizationId, $ownerType);

            if ($placed !== null) {
                return $placed;
            }
        }

        return $this->platformDefault();
    }

    /**
     * Le repli est **déclaré, jamais deviné** — et il n'a qu'un rang : il ne
     * parcourt pas la liste.
     *
     * C'est la règle de bascule des agrégateurs de paiement, transposée. Elle y
     * est délibérément étroite parce qu'un repli commode finit par produire une
     * conséquence que personne n'a choisie.
     */
    private function named(string $slug, ?string $fallback, FileActor $actor): Destination
    {
        $first = $this->eligible($slug, $actor);

        if ($first !== null) {
            return $first;
        }

        if ($fallback === null) {
            throw DomainException::conflict(
                'STORAGE_DESTINATION_UNVERIFIED',
                __('storage::messages.destination_unavailable', ['slug' => $slug]),
            );
        }

        $second = $this->eligible($fallback, $actor);

        if ($second === null) {
            throw DomainException::conflict(
                'STORAGE_DESTINATION_UNVERIFIED',
                __('storage::messages.destination_and_fallback_unavailable', [
                    'slug' => $slug,
                    'fallback' => $fallback,
                ]),
            );
        }

        // Un repli silencieux serait un repli qu'on découvre en cherchant
        // pourquoi les octets ne sont pas là où on les croyait.
        Log::warning('storage: repli de destination', ['demandee' => $slug, 'utilisee' => $fallback]);

        return $second;
    }

    /**
     * Éligible = elle existe, elle accepte des écritures, elle est du bon
     * environnement, et cet acteur a le droit de s'en servir.
     */
    private function eligible(string $slug, FileActor $actor): ?Destination
    {
        $destination = Destination::query()->where('slug', $slug)->first();

        if ($destination === null) {
            throw DomainException::notFound(
                'STORAGE_DESTINATION_NOT_FOUND',
                __('storage::messages.destination_not_found'),
            );
        }

        $this->guardOwnership($destination, $actor);

        if ($destination->environment !== app()->environment()) {
            return null;
        }

        return $destination->acceptsWrites() ? $destination : null;
    }

    /**
     * Une destination de la plateforme sert tout le monde ; celle d'un tiers ne
     * sert que lui.
     *
     * Sans ce contrôle, connaître le nom d'un magasin suffirait à y écrire — et
     * à faire porter la facture cloud d'autrui.
     */
    private function guardOwnership(Destination $destination, FileActor $actor): void
    {
        if ($destination->belongsToPlatform()) {
            return;
        }

        $sien = ($destination->owner_api_key_id !== null && $destination->owner_api_key_id === $actor->id)
            || ($destination->owner_organization_id !== null && $destination->owner_organization_id === $actor->organizationId);

        if (! $sien) {
            throw DomainException::forbidden(
                'STORAGE_DESTINATION_FORBIDDEN',
                __('storage::messages.destination_forbidden'),
            );
        }
    }

    private function fromPlacements(string $organizationId, string $ownerType): ?Destination
    {
        $placements = StoragePlacement::query()
            ->with('destination')
            ->where('organization_id', $organizationId)
            ->where(fn ($query) => $query->where('owner_type', $ownerType)->orWhereNull('owner_type'))
            ->get();

        // Une règle typée l'emporte sur une règle attrape-tout : c'est la plus
        // précise, donc la plus délibérée.
        $ordered = $placements->sortByDesc(fn (StoragePlacement $p): int => $p->owner_type === null ? 0 : 1);

        foreach ($ordered as $placement) {
            $destination = $placement->destination;

            if ($destination !== null && $destination->acceptsWrites() && $destination->environment === app()->environment()) {
                return $destination;
            }
        }

        return null;
    }

    /**
     * L'absence de destination par défaut n'est pas un cas nominal : c'est une
     * plateforme non configurée, et le dire franchement vaut mieux qu'écrire
     * quelque part au hasard.
     */
    private function platformDefault(): Destination
    {
        $destination = Destination::query()
            ->where('is_default', true)
            ->where('environment', app()->environment())
            ->first();

        if ($destination === null || ! $destination->acceptsWrites()) {
            throw DomainException::conflict(
                'STORAGE_DESTINATION_UNVERIFIED',
                __('storage::messages.no_default_destination'),
            );
        }

        return $destination;
    }
}
