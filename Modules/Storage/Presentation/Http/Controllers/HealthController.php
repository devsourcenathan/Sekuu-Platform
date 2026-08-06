<?php

declare(strict_types=1);

namespace Modules\Storage\Presentation\Http\Controllers;

use App\Platform\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Modules\Storage\Domain\Models\Destination;

/**
 * Les magasins, et lesquels servent réellement.
 *
 * ## Pourquoi une route publique
 *
 * L'état d'une destination ne vit qu'en base, et sur une offre sans shell il
 * n'existe aucun moyen de le consulter — le journal du démarrage passe, puis
 * disparaît. Une destination tombée après coup serait alors invisible jusqu'au
 * premier téléversement refusé.
 *
 * C'est la vérification d'avant-vol du module, comme `payments/health` l'est
 * pour les agrégateurs.
 *
 * ## Ce qu'elle ne dit pas
 *
 * Ni le compartiment, ni le point d'accès, ni le message brut du fournisseur —
 * une erreur S3 peut porter un identifiant de compte, un ARN, un nom de rôle.
 * Seulement une **raison** d'un jeu fermé, qui suffit à agir.
 *
 * @see docs/03-services/storage/06-destinations.md
 */
final class HealthController
{
    public function __invoke(): JsonResponse
    {
        $destinations = Destination::query()
            ->where('environment', app()->environment())
            ->orderByDesc('is_default')
            ->orderBy('slug')
            ->get();

        $servable = $destinations->filter(fn (Destination $d): bool => $d->acceptsWrites());

        return ApiResponse::success([
            // La seule question qui compte : un fichier peut-il être déposé ?
            'can_store' => $servable->isNotEmpty()
                && $destinations->contains(fn (Destination $d): bool => $d->is_default && $d->acceptsWrites()),

            'destinations' => $destinations->map(fn (Destination $d): array => [
                'slug' => $d->slug,
                'driver' => $d->preset ?? $d->driver,
                'status' => $d->status,
                'is_default' => $d->is_default,
                'owned_by' => $d->belongsToPlatform() ? 'platform' : 'tenant',
                'verified_at' => $d->verified_at?->toIso8601String(),
                'reason' => $d->verification_reason,
            ])->values()->all(),
        ]);
    }
}
