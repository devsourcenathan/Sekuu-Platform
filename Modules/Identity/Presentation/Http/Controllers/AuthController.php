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
use Modules\Identity\Application\Auth\RequestPasswordReset;
use Modules\Identity\Application\Auth\ResetPassword;
use Modules\Identity\Application\Auth\SessionTokenService;
use Modules\Identity\Application\Auth\SwitchOrganization;
use Modules\Identity\Application\Auth\VerifyEmail;
use Modules\Identity\Application\Events\IdentityEvents;
use Modules\Identity\Domain\AuthenticatedContext;
use Modules\Identity\Presentation\Http\Requests\ForgotPasswordRequest;
use Modules\Identity\Presentation\Http\Requests\LoginRequest;
use Modules\Identity\Presentation\Http\Requests\RegisterRequest;
use Modules\Identity\Presentation\Http\Requests\ResetPasswordRequest;
use Modules\Identity\Presentation\Http\Requests\SwitchOrganizationRequest;
use Modules\Identity\Presentation\Http\Requests\VerifyEmailRequest;
use Modules\Identity\Presentation\Http\Responses\AuthPayload;

/**
 * @see docs/03-services/identity/03-api.md
 */
final class AuthController
{
    public function __construct(
        private readonly SessionTokenService $tokens,
        private readonly AuditLogger $audit,
        private readonly IdentityEvents $events,
    ) {}

    public function register(
        RegisterRequest $request,
        RegisterUser $register,
        VerifyEmail $verify,
    ): JsonResponse {
        $user = $register->handle($request->validated());

        $pair = $this->tokens->start($user, DeviceInfo::fromRequest($request));

        $this->audit->record(AuditAction::USER_REGISTERED, user: $user, target: $user);

        $verificationToken = $verify->issueFor($user);
        $this->audit->record(AuditAction::EMAIL_VERIFICATION_SENT, user: $user);

        $this->events->userRegistered(
            userId: $user->id,
            email: $user->email,
            firstName: $user->first_name,
            locale: $user->language ?? 'fr',
            verificationUrl: self::verificationUrl($verificationToken),
        );

        $payload = AuthPayload::forTokenPair($pair, $user);

        if (app()->environment('local', 'testing')) {
            $payload['email_verification_token'] = $verificationToken;
        }

        return ApiResponse::created($payload)
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
            throw new DomainException('INVALID_TOKEN', __('identity::messages.refresh_missing'), 401);
        }

        $pair = $this->tokens->rotate($presented);

        return ApiResponse::success(
            AuthPayload::forTokenPair($pair, $pair->session->user()->firstOrFail())
        )->withCookie(AuthPayload::refreshCookie($pair->refreshToken));
    }

    /**
     * Demande de réinitialisation.
     *
     * Répond toujours 202, que l'adresse existe ou non : toute différence
     * permettrait d'énumérer les comptes.
     */
    public function forgotPassword(
        ForgotPasswordRequest $request,
        RequestPasswordReset $reset,
    ): JsonResponse {
        $email = $request->string('email')->toString();

        $issued = $reset->handle($email);

        if ($issued !== null) {
            $this->audit->record(AuditAction::PASSWORD_RESET_REQUESTED, user: $issued['user']);

            $this->events->passwordResetRequested(
                userId: $issued['user']->id,
                email: $issued['user']->email,
                firstName: $issued['user']->first_name,
                locale: $issued['user']->language ?? 'fr',
                resetUrl: self::frontendUrl('/reset-password', $issued['token']),
                expiresInHours: (int) ceil(config('identity.tokens.password_reset_ttl') / 3600),
            );
        }

        $payload = ['message' => __('identity::messages.password_reset_sent')];

        // Le jeton n'est exposé qu'en développement : en production il
        // n'existe que dans le message envoyé par Notify.
        if ($issued !== null && app()->environment('local', 'testing')) {
            $payload['token'] = $issued['token'];
        }

        return ApiResponse::success($payload, status: 202);
    }

    public function resetPassword(ResetPasswordRequest $request, ResetPassword $reset): JsonResponse
    {
        $user = $reset->handle(
            $request->string('token')->toString(),
            $request->string('password')->toString(),
        );

        $this->audit->record(AuditAction::PASSWORD_RESET, user: $user, target: $user);

        // Alerte de sécurité : si l'utilisateur n'est pas à l'origine de la
        // réinitialisation, c'est ce message qui le lui apprend.
        $this->events->passwordChanged(
            userId: $user->id,
            email: $user->email,
            firstName: $user->first_name,
            locale: $user->language ?? 'fr',
            ipAddress: $request->ip(),
            phone: $user->phone,
        );

        return ApiResponse::success([
            'message' => __('identity::messages.password_reset_done'),
        ])->withCookie(AuthPayload::forgetRefreshCookie());
    }

    public function verifyEmail(VerifyEmailRequest $request, VerifyEmail $verify): JsonResponse
    {
        $user = $verify->handle($request->string('token')->toString());

        $this->audit->record(AuditAction::EMAIL_VERIFIED, user: $user, target: $user);

        return ApiResponse::success([
            'email' => $user->email,
            'email_verified_at' => $user->email_verified_at?->toIso8601ZuluString(),
        ]);
    }

    public function resendVerification(AuthenticatedContext $context, VerifyEmail $verify): JsonResponse
    {
        $token = $verify->issueFor($context->user);

        $this->audit->record(AuditAction::EMAIL_VERIFICATION_SENT, user: $context->user);

        $this->events->emailVerificationRequested(
            userId: $context->user->id,
            email: $context->user->email,
            firstName: $context->user->first_name,
            locale: $context->user->language ?? 'fr',
            verificationUrl: self::verificationUrl($token),
            expiresInHours: (int) ceil(config('identity.tokens.email_verification_ttl') / 3600),
        );

        $payload = ['message' => __('identity::messages.verification_sent')];

        if (app()->environment('local', 'testing')) {
            $payload['token'] = $token;
        }

        return ApiResponse::success($payload, status: 202);
    }

    private static function verificationUrl(string $token): string
    {
        return self::frontendUrl('/verify-email', $token);
    }

    /**
     * Les liens pointent vers l'application, pas vers l'API : c'est un humain
     * qui clique, pas un client HTTP.
     */
    private static function frontendUrl(string $path, string $token): string
    {
        $base = rtrim((string) config('identity.frontend_url', config('app.url')), '/');

        return $base.$path.'?token='.$token;
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
