<?php

declare(strict_types=1);

namespace Modules\Identity\Presentation\Http\Controllers;

use App\Platform\Exceptions\DomainException;
use App\Platform\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Identity\Application\Audit\AuditAction;
use Modules\Identity\Application\Audit\AuditLogger;
use Modules\Identity\Application\Auth\DeviceInfo;
use Modules\Identity\Application\Auth\SessionTokenService;
use Modules\Identity\Application\OAuth\OAuthFlow;
use Modules\Identity\Domain\AuthenticatedContext;
use Modules\Identity\Domain\Models\OAuthAccount;
use Modules\Identity\Presentation\Http\Responses\AuthPayload;

/**
 * @see docs/03-services/identity/03-api.md
 */
final class OAuthController
{
    public function __construct(
        private readonly OAuthFlow $flow,
        private readonly SessionTokenService $tokens,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Démarre le flux. L'API étant consommée par des clients web et mobiles,
     * elle renvoie l'URL plutôt que d'émettre une redirection HTTP.
     */
    public function redirect(string $provider): JsonResponse
    {
        return ApiResponse::success($this->flow->start($provider));
    }

    public function callback(Request $request, string $provider): JsonResponse
    {
        $code = $request->query('code');
        $state = $request->query('state');

        if (! is_string($code) || ! is_string($state)) {
            throw new DomainException(
                'OAUTH_STATE_INVALID',
                __('identity::messages.oauth_state_invalid'),
                400,
            );
        }

        $outcome = $this->flow->complete($provider, $code, $state);

        $pair = $this->tokens->start($outcome->user, DeviceInfo::fromRequest($request));

        if ($outcome->accountCreated) {
            $this->audit->record(AuditAction::USER_REGISTERED, user: $outcome->user, target: $outcome->user);
        }

        if ($outcome->accountLinked) {
            $this->audit->record(
                AuditAction::OAUTH_LINKED,
                user: $outcome->user,
                payload: ['provider' => $provider],
            );
        }

        $this->audit->record(
            AuditAction::AUTH_LOGIN,
            user: $outcome->user,
            target: $pair->session,
            payload: ['provider' => $provider],
        );

        return ApiResponse::success(
            AuthPayload::forTokenPair($pair, $outcome->user) + ['account_created' => $outcome->accountCreated]
        )->withCookie(AuthPayload::refreshCookie($pair->refreshToken));
    }

    public function index(AuthenticatedContext $context): JsonResponse
    {
        $accounts = OAuthAccount::query()
            ->where('user_id', $context->user->id)
            ->orderBy('provider')
            ->get()
            ->map(fn (OAuthAccount $account) => [
                'id' => $account->id,
                'provider' => $account->provider,
                'email' => $account->email,
                'linked_at' => $account->created_at?->toIso8601ZuluString(),
            ])
            ->all();

        return ApiResponse::success($accounts);
    }

    public function destroy(AuthenticatedContext $context, string $accountId): JsonResponse
    {
        $account = OAuthAccount::query()
            ->where('user_id', $context->user->id)
            ->whereKey($accountId)
            ->first();

        if ($account === null) {
            throw DomainException::notFound(
                'RESOURCE_NOT_FOUND',
                __('identity::messages.oauth_account_not_found'),
            );
        }

        $this->assertNotTheLastSignInMethod($context, $account);

        $provider = $account->provider;
        $account->delete();

        $this->audit->record(
            AuditAction::OAUTH_UNLINKED,
            user: $context->user,
            payload: ['provider' => $provider],
        );

        return ApiResponse::noContent();
    }

    /**
     * Délier le dernier moyen de connexion enfermerait l'utilisateur dehors.
     */
    private function assertNotTheLastSignInMethod(AuthenticatedContext $context, OAuthAccount $account): void
    {
        if ($context->user->password_hash !== null) {
            return;
        }

        $remaining = OAuthAccount::query()
            ->where('user_id', $context->user->id)
            ->whereKeyNot($account->id)
            ->count();

        if ($remaining === 0) {
            throw DomainException::conflict(
                'RESOURCE_CONFLICT',
                __('identity::messages.oauth_last_method'),
            );
        }
    }
}
