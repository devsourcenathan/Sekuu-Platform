<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Les caches compilés, et ce que leur présence ferait mentir.
     *
     * Le premier est le plus grave. `phpunit.xml` neutralise les identifiants
     * des fournisseurs par des balises `<env>`, qui ne fonctionnent que si
     * `env()` est réellement appelé — or `php artisan config:cache` compile la
     * configuration depuis le **vrai `.env`**, et `env()` n'est alors plus
     * jamais consulté.
     *
     * Autrement dit : un `bootstrap/cache/config.php` oublié fait tourner toute
     * la suite avec les clés de production. C'est le mécanisme exact qui a fait
     * partir une centaine de vrais emails, et `TestEnvironmentIsolationTest` ne
     * l'attrape qu'indirectement — seulement si une clé se trouve renseignée.
     *
     * Les deux autres ne coûtent pas d'argent, mais rendent des tests
     * silencieusement faux : un test de sous-domaine passe au vert sur des
     * routes figées avant que la configuration ne change.
     *
     * @var array<string, string>
     */
    private const CACHES = [
        'config' => 'la suite tournerait avec les identifiants du .env, y compris ceux de production',
        'routes-v7' => 'les routes ne suivraient plus la configuration, et un test de sous-domaine mentirait',
        'events' => 'les abonnés ne seraient plus ceux que la suite croit enregistrer',
    ];

    /**
     * Le contrôle vit ici plutôt que dans un test dédié : il doit échouer
     * **avant** qu'un seul test ne s'exécute, pas au milieu de la suite.
     */
    protected function setUp(): void
    {
        foreach (self::CACHES as $fichier => $consequence) {
            if (! file_exists(__DIR__.'/../bootstrap/cache/'.$fichier.'.php')) {
                continue;
            }

            throw new RuntimeException(sprintf(
                'Le cache `%s` est présent : %s. Exécutez `php artisan optimize:clear`.',
                $fichier,
                $consequence,
            ));
        }

        parent::setUp();
    }
}
