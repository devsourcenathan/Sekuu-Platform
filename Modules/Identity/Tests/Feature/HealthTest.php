<?php

declare(strict_types=1);

namespace Modules\Identity\Tests\Feature;

use Illuminate\Routing\RouteCollection;
use Modules\Identity\IdentityServiceProvider;
use Tests\TestCase;

final class HealthTest extends TestCase
{
    public function test_health_endpoint_is_exposed_under_the_versioned_prefix(): void
    {
        $this->getJson('/api/v1/health')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.service', 'identity')
            ->assertJsonPath('data.version', 'v1');
    }

    public function test_unversioned_route_is_not_exposed(): void
    {
        $this->getJson('/api/health')->assertNotFound();
    }

    /**
     * Le sous-domaine vient de la **configuration**, et les routes y sont liées
     * au boot : on reconstruit donc les routes, on ne recharge pas
     * l'environnement.
     *
     * La version précédente posait `SEKUU_DOMAIN_IDENTITY` puis appelait
     * `refreshApplication()`. Cela ne tenait que par accident :
     * `refreshApplication()` relit `.env` et **écrase** les trois sources que
     * `env()` consulte — `putenv`, `$_ENV` et `$_SERVER`. Le test passait donc
     * sur une machine dont le `.env` ne porte pas la clé, faute de quoi
     * l'écraser, et échouait partout où `.env.example` la définit, vide.
     */
    public function test_routes_are_bound_to_the_module_subdomain_when_configured(): void
    {
        config(['sekuu.domains.identity' => 'identity.sekuu.test']);

        // Les routes déjà enregistrées l'ont été sans sous-domaine : on repart
        // d'une table vide, puis on relance l'enregistrement du module.
        $this->app['router']->setRoutes(new RouteCollection);
        (new IdentityServiceProvider($this->app))->boot();

        $this->getJson('http://identity.sekuu.test/api/v1/health')->assertOk();

        // Et nulle part ailleurs : c'est tout l'intérêt du sous-domaine.
        $this->getJson('http://verify.sekuu.test/api/v1/health')->assertNotFound();
    }
}
