<?php

declare(strict_types=1);

namespace Modules\Identity\Presentation\Http\Responses;

use Illuminate\Support\Facades\Cookie;
use Modules\Identity\Application\Auth\TokenPair;
use Modules\Identity\Domain\Models\Membership;
use Modules\Identity\Domain\Models\User;
use Modules\Identity\Infrastructure\Jwt\IssuedAccessToken;
use Symfony\Component\HttpFoundation\Cookie as SymfonyCookie;

/**
 * Sérialisation commune aux réponses d'authentification.
 *
 * @see docs/03-services/identity/03-api.md
 */
final class AuthPayload
{
    /**
     * @return array<string, mixed>
     */
    public static function forTokenPair(TokenPair $pair, User $user): array
    {
        return array_merge(
            self::forAccessToken($pair->accessToken),
            [
                // Également posé en cookie HttpOnly (voir refreshCookie).
                // Les clients natifs, qui n'ont pas de gestion de cookies,
                // le lisent ici.
                'refresh_token' => $pair->refreshToken,
                'session_id' => $pair->session->id,
                'user' => self::user($user),
                'organizations' => self::organizations($user),
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public static function forAccessToken(IssuedAccessToken $token): array
    {
        return [
            'access_token' => $token->token,
            'token_type' => 'Bearer',
            'expires_in' => $token->expiresIn,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function user(User $user): array
    {
        return [
            'id' => $user->id,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'email' => $user->email,
            'phone' => $user->phone,
            'avatar_url' => $user->avatar_url,
            'language' => $user->language,
            'timezone' => $user->timezone,
            'email_verified_at' => $user->email_verified_at?->toIso8601ZuluString(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function organizations(User $user): array
    {
        return $user->memberships()
            ->where('status', 'active')
            ->with(['organization', 'roles'])
            ->get()
            ->filter(fn (Membership $m) => $m->organization !== null)
            ->map(fn (Membership $m) => [
                'id' => $m->organization->id,
                'name' => $m->organization->name,
                'slug' => $m->organization->slug,
                'roles' => $m->roleSlugs(),
            ])
            ->values()
            ->all();
    }

    /**
     * Cookie HttpOnly : c'est le transport prévu pour les clients web, où
     * localStorage est hors de question.
     */
    public static function refreshCookie(string $refreshToken): SymfonyCookie
    {
        return Cookie::make(
            name: config('identity.refresh_token.cookie'),
            value: $refreshToken,
            minutes: (int) (config('identity.refresh_token.ttl') / 60),
            path: '/',
            domain: null,
            secure: ! app()->environment('local', 'testing'),
            httpOnly: true,
            raw: false,
            sameSite: 'lax',
        );
    }

    public static function forgetRefreshCookie(): SymfonyCookie
    {
        return Cookie::forget(config('identity.refresh_token.cookie'));
    }
}
