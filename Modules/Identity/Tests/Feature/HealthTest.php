<?php

declare(strict_types=1);

namespace Modules\Identity\Tests\Feature;

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

    public function test_routes_are_bound_to_the_module_subdomain_when_configured(): void
    {
        // Le sous-domaine est résolu au boot, depuis l'environnement : il faut
        // donc le poser avant de reconstruire l'application.
        putenv('SEKUU_DOMAIN_IDENTITY=identity.sekuu.test');
        $_ENV['SEKUU_DOMAIN_IDENTITY'] = 'identity.sekuu.test';

        $this->refreshApplication();

        $this->assertSame('identity.sekuu.test', config('sekuu.domains.identity'));

        $this->getJson('http://verify.sekuu.test/api/v1/health')->assertNotFound();
        $this->getJson('http://identity.sekuu.test/api/v1/health')->assertOk();
    }

    protected function tearDown(): void
    {
        putenv('SEKUU_DOMAIN_IDENTITY');
        unset($_ENV['SEKUU_DOMAIN_IDENTITY']);

        parent::tearDown();
    }
}
