<?php

declare(strict_types=1);

namespace Modules\Identity\Application\Auth;

use App\Platform\Exceptions\DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Identity\Domain\Models\Membership;
use Modules\Identity\Domain\Models\RefreshToken;
use Modules\Identity\Domain\Models\User;
use Modules\Identity\Domain\Models\UserSession;
use Modules\Identity\Domain\TokenContext;
use Modules\Identity\Infrastructure\Jwt\AccessTokenIssuer;
use Modules\Identity\Infrastructure\Jwt\IssuedAccessToken;

/**
 * Émission et rotation des jetons de session.
 *
 * @see docs/02-standards/security.md
 */
final class SessionTokenService
{
    public function __construct(
        private readonly AccessTokenIssuer $issuer,
        private readonly int $refreshTtl,
        private readonly int $sessionTtl,
    ) {}

    /**
     * Ouvre une session (un appareil) et émet la première paire de jetons.
     */
    public function start(User $user, DeviceInfo $device, ?Membership $membership = null): TokenPair
    {
        $session = UserSession::create([
            'user_id' => $user->id,
            'device_name' => $device->deviceName,
            'platform' => $device->platform,
            'browser' => $device->browser,
            'ip_address' => $device->ipAddress,
            'last_activity' => now(),
            'expires_at' => now()->addSeconds($this->sessionTtl),
        ]);

        return $this->issue($user, $session, $membership);
    }

    /**
     * Émet une paire de jetons pour une session existante.
     */
    public function issue(
        User $user,
        UserSession $session,
        ?Membership $membership = null,
        ?RefreshToken $parent = null,
    ): TokenPair {
        $plainRefreshToken = self::generateRefreshToken();

        $refreshToken = RefreshToken::create([
            'session_id' => $session->id,
            'user_id' => $user->id,
            'token_hash' => RefreshToken::hash($plainRefreshToken),
            'parent_id' => $parent?->id,
            'expires_at' => now()->addSeconds($this->refreshTtl),
        ]);

        $session->forceFill(['last_activity' => now()])->save();

        return new TokenPair(
            accessToken: $this->issuer->issue($this->contextFor($user, $session, $membership)),
            refreshToken: $plainRefreshToken,
            session: $session->refresh(),
        );
    }

    /**
     * Émet uniquement un nouvel access token, sans toucher au refresh token.
     * Utilisé par le changement d'organisation : le contexte change, pas la session.
     */
    public function reissueAccessToken(User $user, UserSession $session, ?Membership $membership): IssuedAccessToken
    {
        return $this->issuer->issue($this->contextFor($user, $session, $membership));
    }

    /**
     * Rotation : le jeton présenté est révoqué et remplacé.
     *
     * Si un jeton déjà révoqué est présenté, c'est le signe d'un vol : toute la
     * session est révoquée, et pas seulement le jeton concerné.
     */
    public function rotate(string $plainRefreshToken): TokenPair
    {
        try {
            return DB::transaction(fn (): TokenPair => $this->rotateWithinTransaction($plainRefreshToken));
        } catch (RefreshTokenReplayed $replay) {
            // La révocation est appliquée hors transaction : à l'intérieur,
            // le rollback provoqué par l'exception l'annulerait, et le vol
            // resterait sans conséquence.
            UserSession::query()->find($replay->sessionId)?->revoke();

            throw new DomainException(
                'TOKEN_REVOKED',
                __('The refresh token has already been used. The session has been revoked.'),
                401,
            );
        }
    }

    private function rotateWithinTransaction(string $plainRefreshToken): TokenPair
    {
        $token = RefreshToken::query()
            ->where('token_hash', RefreshToken::hash($plainRefreshToken))
            ->lockForUpdate()
            ->first();

        if ($token === null) {
            throw new DomainException('INVALID_TOKEN', __('The refresh token is invalid.'), 401);
        }

        $session = $token->session()->first();

        if ($token->revoked_at !== null) {
            throw new RefreshTokenReplayed($session?->id);
        }

        if ($token->expires_at->isPast()) {
            throw new DomainException('TOKEN_EXPIRED', __('The refresh token has expired.'), 401);
        }

        if ($session === null || ! $session->isUsable()) {
            throw new DomainException('TOKEN_REVOKED', __('The session is no longer active.'), 401);
        }

        $user = $session->user()->first();

        if ($user === null || ! $user->isActive()) {
            throw new DomainException('ACCOUNT_SUSPENDED', __('This account is not active.'), 403);
        }

        $token->revoke();

        // La rotation n'emporte pas le contexte d'organisation : le client
        // rappelle switch-organization s'il en avait un. Cela évite qu'un
        // token rafraîchi conserve des rôles révoqués entre-temps.
        return $this->issue($user, $session, null, $token);
    }

    private function contextFor(User $user, UserSession $session, ?Membership $membership): TokenContext
    {
        if ($membership === null) {
            return new TokenContext(
                userId: $user->id,
                sessionId: $session->id,
                language: $user->language ?? 'fr',
            );
        }

        return new TokenContext(
            userId: $user->id,
            sessionId: $session->id,
            organizationId: $membership->organization_id,
            roles: $membership->roleSlugs(),
            scopes: $membership->scopes(),
            products: $membership->organization?->activeProductSlugs() ?? [],
            language: $user->language ?? 'fr',
        );
    }

    /** 256 bits d'aléa, sans lien avec l'utilisateur. */
    private static function generateRefreshToken(): string
    {
        return Str::random(64);
    }
}
