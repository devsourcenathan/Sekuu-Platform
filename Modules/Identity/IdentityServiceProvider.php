<?php

declare(strict_types=1);

namespace Modules\Identity;

use App\Platform\Support\ModuleServiceProvider;
use Illuminate\Support\Facades\Auth;
use Modules\Identity\Application\Auth\SessionTokenService;
use Modules\Identity\Domain\AuthenticatedContext;
use Modules\Identity\Infrastructure\Auth\JwtUserResolver;
use Modules\Identity\Infrastructure\Console\GenerateJwtKeysCommand;
use Modules\Identity\Infrastructure\Jwt\AccessTokenIssuer;
use Modules\Identity\Infrastructure\Jwt\AccessTokenVerifier;
use Modules\Identity\Infrastructure\Jwt\SigningKeys;

final class IdentityServiceProvider extends ModuleServiceProvider
{
    protected function moduleSlug(): string
    {
        return 'identity';
    }

    protected function modulePath(): string
    {
        return __DIR__;
    }

    public function register(): void
    {
        $this->app->singleton(SigningKeys::class, function ($app): SigningKeys {
            return new SigningKeys(
                configuredPrivateKey: config('identity.jwt.private_key'),
                configuredPublicKey: config('identity.jwt.public_key'),
                // Chemin vide en test : la paire reste en mémoire.
                storagePath: $app->runningUnitTests() ? '' : storage_path('app/private/identity'),
                mayGenerate: ! $app->environment('production'),
            );
        });

        $this->app->singleton(AccessTokenIssuer::class, fn ($app) => new AccessTokenIssuer(
            keys: $app->make(SigningKeys::class),
            issuer: config('identity.jwt.issuer'),
            audience: config('identity.jwt.audience'),
            ttl: config('identity.jwt.access_ttl'),
        ));

        $this->app->singleton(AccessTokenVerifier::class, fn ($app) => new AccessTokenVerifier(
            keys: $app->make(SigningKeys::class),
            issuer: config('identity.jwt.issuer'),
            audience: config('identity.jwt.audience'),
            leeway: config('identity.jwt.leeway'),
        ));

        $this->app->singleton(SessionTokenService::class, fn ($app) => new SessionTokenService(
            issuer: $app->make(AccessTokenIssuer::class),
            refreshTtl: config('identity.refresh_token.ttl'),
            sessionTtl: config('identity.session.ttl'),
        ));

        $this->app->singleton(JwtUserResolver::class);

        // Injectable directement dans les contrôleurs. La résolution part
        // toujours de la requête courante : rien n'est mémorisé dans le
        // conteneur, qui survivrait d'une requête à l'autre.
        $this->app->bind(
            AuthenticatedContext::class,
            fn ($app) => $app->make(JwtUserResolver::class)->require($app->make('request')),
        );
    }

    public function boot(): void
    {
        parent::boot();

        if ($this->app->runningInConsole()) {
            $this->commands([GenerateJwtKeysCommand::class]);
        }

        Auth::viaRequest(
            'sekuu-jwt',
            fn ($request) => $this->app->make(JwtUserResolver::class)->user($request),
        );
    }
}
