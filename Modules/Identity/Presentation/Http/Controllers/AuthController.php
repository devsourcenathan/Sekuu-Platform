<?php

declare(strict_types=1);

namespace Modules\Identity\Presentation\Http\Controllers;

use App\Platform\Exceptions\DomainException;
use App\Platform\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Identity\Application\Audit\AuditAction;
use Modules\Identity\Application\Audit\AuditLogger;
use Modules\Identity\Application\Auth\AuthenticateUser;
use Modules\Identity\Application\Auth\DeviceInfo;
use Modules\Identity\Application\Auth\RegisterUser;
use Modules\Identity\Application\Auth\SessionTokenService;
use Modules\Identity\Application\Auth\SwitchOrganization;
use Modules\Identity\Domain\AuthenticatedContext;
use Modules\Identity\Presentation\Http\Requests\LoginRequest;
use Modules\Identity\Presentation\Http\Requests\RegisterRequest;
use Modules\Identity\Presentation\Http\Requests\SwitchOrganizationRequest;
use Modules\Identity\Presentation\Http\Responses\AuthPayload;

/**
 * @see docs/03-services/identity/03-api.md
 */
final class AuthController
{
    public function __construct(
        private readonly SessionTokenService $tokens,
        private readonly AuditLogger $audit,
    ) {}

    public function register(RegisterRequest $request, RegisterUser $register): JsonResponse
    {
        $user = $register->handle($request->validated());

        $pair = $this->tokens->start($user, DeviceInfo::fromRequest($request));

        $this->audit->record(AuditAction::USER_REGISTERED, user: $user, target: $user);

        return ApiResponse::created(AuthPayload::forTokenPair($pair, $user))
            ->withCookie(AuthPayload::refreshCookie($pair->refreshToken));
    }

    public function login(LoginRequest $request, AuthenticateUser $authenticate): JsonResponse
    {
        $email = $request->string('email')->toString();

        try {
            $user = $authenticate->handle($email, $request->string('password')->toString());
        } catch (DomainException $e) {
            // Les échecs sont journalisés autant que les succès : c'est ce qui
            // permet de repérer une attaque par force brute.
            $this->audit->record(
                AuditAction::AUTH_LOGIN_FAILED,
                payload: ['email' => $email, 'reason' => $e->errorCode],
            );

            throw $e;
        }

        $pair = $this->tokens->start($user, DeviceInfo::fromRequest($request));

        $this->audit->record(AuditAction::AUTH_LOGIN, user: $user, target: $pair->session);

        return ApiResponse::success(AuthPayload::forTokenPair($pair, $user))
            ->withCookie(AuthPayload::refreshCookie($pair->refreshToken));
    }

    /**
     * Rotation du refresh token. Le jeton est lu en priorité dans le cookie,
     * qui est le transport des clients web.
     */
    public function refresh(Request $request): JsonResponse
    {
        $presented = $request->cookie(config('identity.refresh_token.cookie'))
            ?? $request->input('refresh_token');

        if (! is_string($presented) || $presented === '') {
            throw new DomainException('INVALID_TOKEN', __('No refresh token was provided.'), 401);
        }

        $pair = $this->tokens->rotate($presented);

        return ApiResponse::success(
            AuthPayload::forTokenPair($pair, $pair->session->user()->firstOrFail())
        )->withCookie(AuthPayload::refreshCookie($pair->refreshToken));
    }

    public function me(AuthenticatedContext $context): JsonResponse
    {
        return ApiResponse::success([
            'user' => AuthPayload::user($context->user),
            'organizations' => AuthPayload::organizations($context->user),
            'context' => [
                'session_id' => $context->session->id,
                'organization_id' => $context->token->organizationId,
                'workspace_id' => $context->token->workspaceId,
                'roles' => $context->token->roles,
                'scopes' => $context->token->scopes,
                'products' => $context->token->products,
            ],
        ]);
    }

    public function switchOrganization(
        SwitchOrganizationRequest $request,
        AuthenticatedContext $context,
        SwitchOrganization $switch,
    ): JsonResponse {
        $organizationId = $request->string('organization_id')->toString();

        $accessToken = $switch->handle($context->user, $context->session, $organizationId);

        $this->audit->record(
            AuditAction::AUTH_ORGANIZATION_SWITCHED,
            user: $context->user,
            organizationId: $organizationId,
        );

        return ApiResponse::success(AuthPayload::forAccessToken($accessToken));
    }

    public function logout(AuthenticatedContext $context): JsonResponse
    {
        $context->session->revoke();

        $this->audit->record(
            AuditAction::AUTH_LOGOUT,
            user: $context->user,
            organizationId: $context->token->organizationId,
            target: $context->session,
        );

        return ApiResponse::success(null)
            ->withCookie(AuthPayload::forgetRefreshCookie());
    }

    /**
     * Déconnexion de tous les appareils : chaque session est révoquée avec ses
     * refresh tokens.
     */
    public function logoutAll(AuthenticatedContext $context): JsonResponse
    {
        $sessions = $context->user->sessions()->whereNull('revoked_at')->get();

        $sessions->each(fn ($session) => $session->revoke());

        $this->audit->record(
            AuditAction::AUTH_LOGOUT_ALL,
            user: $context->user,
            organizationId: $context->token->organizationId,
            payload: ['sessions_revoked' => $sessions->count()],
        );

        return ApiResponse::success(null)
            ->withCookie(AuthPayload::forgetRefreshCookie());
    }
}
