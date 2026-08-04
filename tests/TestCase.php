<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Une configuration mise en cache annule toute l'isolation de la suite.
     *
     * `phpunit.xml` neutralise les identifiants des fournisseurs par des
     * balises `<env>`. Elles ne fonctionnent que si `env()` est réellement
     * appelé — or `php artisan config:cache` compile la configuration depuis le
     * **vrai `.env`**, et `env()` n'est alors plus jamais consulté.
     *
     * Autrement dit : un `bootstrap/cache/config.php` oublié fait tourner toute
     * la suite avec les clés de production. C'est le mécanisme exact qui a fait
     * partir une centaine de vrais emails, et `TestEnvironmentIsolationTest` ne
     * l'attrape qu'indirectement — seulement si une clé se trouve renseignée.
     *
     * Le contrôle est ici plutôt que dans un test dédié : il doit échouer
     * **avant** qu'un seul test ne s'exécute, pas au milieu de la suite.
     */
    protected function setUp(): void
    {
        $cache = __DIR__.'/../bootstrap/cache/config.php';

        if (file_exists($cache)) {
            throw new RuntimeException(
                'La configuration est en cache : les neutralisations de phpunit.xml sont '
                .'ignorées, et la suite tournerait avec les identifiants du .env — '
                .'y compris ceux de production. Exécutez `php artisan config:clear`.'
            );
        }

        parent::setUp();
    }
}
