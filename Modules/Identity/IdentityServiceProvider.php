<?php

declare(strict_types=1);

namespace Modules\Identity;

use App\Platform\Contracts\IdentityContract;
use App\Platform\Events\DomainEvent;
use App\Platform\Support\ModuleServiceProvider;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Modules\Identity\Application\Audit\AuditLogger;
use Modules\Identity\Application\Auth\SessionTokenService;
use Modules\Identity\Application\Products\ApplySubscriptionAccess;
use Modules\Identity\Domain\AuthenticatedContext;
use Modules\Identity\Infrastructure\Auth\JwtUserResolver;
use Modules\Identity\Infrastructure\Console\GenerateJwtKeysCommand;
use Modules\Identity\Infrastructure\Contracts\IdentityGateway;
use Modules\Identity\Infrastructure\Jwt\AccessTokenIssuer;
use Modules\Identity\Infrastructure\Jwt\AccessTokenVerifier;
use Modules\Identity\Infrastructure\Jwt\SigningKeys;
use Modules\Identity\Infrastructure\OAuth\OAuthGateway;
use Modules\Identity\Infrastructure\OAuth\SocialiteGateway;
use Modules\Identity\Presentation\Http\Middleware\RequireApiKey;
use Modules\Identity\Presentation\Http\Middleware\RequireOrganization;
use Modules\Identity\Presentation\Http\Middleware\RequireScope;

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

        // Lié par requête, et non en singleton : le service journalise, et le
        // journal doit porter l'IP et le request_id de la requête courante.
        $this->app->bind(SessionTokenService::class, fn ($app) => new SessionTokenService(
            issuer: $app->make(AccessTokenIssuer::class),
            audit: $app->make(AuditLogger::class),
            refreshTtl: config('identity.refresh_token.ttl'),
            sessionTtl: config('identity.session.ttl'),
        ));

        $this->app->bind(
            AuditLogger::class,
            fn ($app) => new AuditLogger($app->make('request')),
        );

        $this->app->singleton(JwtUserResolver::class);

        $this->app->bind(OAuthGateway::class, SocialiteGateway::class);

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

        // Identity expose son contrat de lecture aux autres modules. C'est la
        // seule façon dont ils peuvent l'interroger : jamais son modèle
        // Eloquent, jamais sa table.
        $this->app->singleton(IdentityContract::class, IdentityGateway::class);

        if ($this->app->runningInConsole()) {
            $this->commands([GenerateJwtKeysCommand::class]);
        }

        // Identity étant le fournisseur d'identité, c'est lui qui expose les
        // middlewares d'autorisation globale utilisables par tous les modules.
        $router = $this->app->make(Router::class);
        $router->aliasMiddleware('organization', RequireOrganization::class);
        $router->aliasMiddleware('scope', RequireScope::class);
        $router->aliasMiddleware('api-key', RequireApiKey::class);

        Auth::viaRequest(
            'sekuu-jwt',
            fn ($request) => $this->app->make(JwtUserResolver::class)->user($request),
        );

        // Identity consomme les décisions de Billing : `organization_products`
        // est un cache de droits dérivé, et c'est Billing qui fait foi.
        // L'écoute passe par l'événement générique de la plateforme : aucune
        // dépendance de compilation vers le module émetteur.
        Event::listen(DomainEvent::class, [ApplySubscriptionAccess::class, 'handle']);
    }
}
