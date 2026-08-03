<?php

declare(strict_types=1);

namespace Modules\Identity\Presentation\Http\Controllers;

use App\Platform\Exceptions\DomainException;
use App\Platform\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Modules\Identity\Application\Audit\AuditAction;
use Modules\Identity\Application\Audit\AuditLogger;
use Modules\Identity\Application\Auth\ChangePassword;
use Modules\Identity\Domain\AuthenticatedContext;
use Modules\Identity\Presentation\Http\Requests\ChangePasswordRequest;
use Modules\Identity\Presentation\Http\Responses\AuthPayload;

/**
 * @see docs/03-services/identity/03-api.md
 */
final class UserController
{
    public function changePassword(
        ChangePasswordRequest $request,
        AuthenticatedContext $context,
        ChangePassword $change,
        AuditLogger $audit,
        string $userId,
    ): JsonResponse {
        // Changer le mot de passe d'autrui n'est jamais permis, quel que soit
        // le rôle : un administrateur passe par la réinitialisation.
        if ($userId !== $context->user->id) {
            throw DomainException::forbidden(
                'FORBIDDEN',
                __('You can only change your own password.'),
            );
        }

        $change->handle(
            user: $context->user,
            currentPassword: $request->string('current_password')->toString(),
            newPassword: $request->string('password')->toString(),
            keepSession: $context->session,
        );

        $audit->record(
            AuditAction::PASSWORD_CHANGED,
            user: $context->user,
            organizationId: $context->token->organizationId,
            target: $context->user,
        );

        return ApiResponse::success([
            'message' => __('Your password has been changed. Other devices have been signed out.'),
        ]);
    }

    public function me(AuthenticatedContext $context): JsonResponse
    {
        return ApiResponse::success(AuthPayload::user($context->user));
    }
}
