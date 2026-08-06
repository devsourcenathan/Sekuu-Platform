<?php

declare(strict_types=1);

namespace Modules\AI\Presentation\Http\Controllers;

use App\Platform\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Modules\AI\Domain\Models\AiAccount;

/**
 * Les comptes, et lesquels servent réellement.
 *
 * ## Pourquoi une route publique
 *
 * L'état d'un compte ne vit qu'en base, et sur une offre sans shell il n'existe
 * aucun moyen de le consulter — le journal du démarrage passe, puis disparaît.
 * Un compte tombé après coup serait invisible jusqu'à la première génération
 * refusée, c'est-à-dire jusqu'à ce qu'un client le découvre.
 *
 * C'est la vérification d'avant-vol du module, comme `storage/health` et
 * `payments/health`. Elle a été écrite pour Storage après quatre déploiements
 * dont le diagnostic n'était lisible nulle part.
 *
 * ## Ce qu'elle ne dit pas
 *
 * Ni l'empreinte de la clé, ni le point d'accès, ni le message brut du
 * fournisseur — il peut porter un identifiant d'organisation ou un nom de
 * déploiement. Seulement une **raison** d'un jeu fermé, qui suffit à agir.
 *
 * @see docs/03-services/ai/05-providers.md
 */
final class HealthController
{
    public function __invoke(): JsonResponse
    {
        $accounts = AiAccount::query()
            ->where('environment', app()->environment())
            ->orderBy('priority')
            ->orderBy('slug')
            ->get();

        $platform = $accounts->filter(fn (AiAccount $a): bool => $a->belongsToPlatform() && $a->canGenerate());

        return ApiResponse::success([
            // La seule question qui compte : une génération peut-elle partir ?
            'can_generate' => $platform->isNotEmpty(),

            'accounts' => $accounts->map(fn (AiAccount $a): array => [
                'slug' => $a->slug,
                'provider' => $a->preset ?? $a->driver,
                'status' => $a->status,
                'priority' => $a->priority,
                'owned_by' => $a->belongsToPlatform() ? 'platform' : 'tenant',
                'models' => $a->models,
                'verified_at' => $a->verified_at?->toIso8601String(),
                'reason' => $a->verification_reason,
            ])->values()->all(),
        ]);
    }
}
