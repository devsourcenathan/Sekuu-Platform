<?php

declare(strict_types=1);

namespace Modules\Identity\Presentation\Http\Controllers;

use App\Platform\Exceptions\DomainException;
use App\Platform\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Modules\Identity\Application\Audit\AuditAction;
use Modules\Identity\Application\Audit\AuditLogger;
use Modules\Identity\Domain\AuthenticatedContext;
use Modules\Identity\Domain\Models\UserSession;

/**
 * Appareils connectés de l'utilisateur.
 *
 * @see docs/03-services/identity/03-api.md
 */
final class SessionController
{
    public function index(AuthenticatedContext $context): JsonResponse
    {
        $sessions = UserSession::query()
            ->where('user_id', $context->user->id)
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            // L'identifiant départage les sessions ouvertes dans la même
            // seconde, sinon l'ordre serait indéterminé d'un appel à l'autre.
            ->orderByDesc('last_activity')
            ->orderByDesc('id')
            ->get()
            ->map(fn (UserSession $session) => [
                'id' => $session->id,
                'device_name' => $session->device_name,
                'platform' => $session->platform,
                'browser' => $session->browser,
                'ip_address' => $session->ip_address,
                'last_activity' => $session->last_activity?->toIso8601ZuluString(),
                'expires_at' => $session->expires_at->toIso8601ZuluString(),
                // Permet à l'interface de ne pas proposer « se déconnecter »
                // sur l'appareil qu'on est en train d'utiliser.
                'is_current' => $session->id === $context->session->id,
            ])
            ->all();

        return ApiResponse::success($sessions);
    }

    public function destroy(
        AuthenticatedContext $context,
        AuditLogger $audit,
        string $sessionId,
    ): JsonResponse {
        // La session d'un autre utilisateur est indiscernable d'une session
        // inexistante.
        $session = UserSession::query()
            ->where('user_id', $context->user->id)
            ->whereKey($sessionId)
            ->first();

        if ($session === null) {
            throw DomainException::notFound(
                'RESOURCE_NOT_FOUND',
                __('identity::messages.session_not_found'),
            );
        }

        $session->revoke();

        $audit->record(
            AuditAction::SESSION_REVOKED,
            user: $context->user,
            organizationId: $context->token->organizationId,
            target: $session,
            payload: ['was_current' => $session->id === $context->session->id],
        );

        return ApiResponse::noContent();
    }
}
