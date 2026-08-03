<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;
use Throwable;

/**
 * Les guidelines déclarent le contrat OpenAPI source de vérité. Sans ce test,
 * il dériverait dès la première route ajoutée sans être documentée.
 *
 * La vérification est **par module** : les routes sont rattachées à leur
 * contrat par le préfixe de leur nom (`identity.v1.`, `notify.v1.`).
 *
 * @see docs/02-standards/api-guidelines.md
 */
final class OpenApiContractTest extends TestCase
{
    /**
     * Le data provider s'exécute avant le boot de l'application : `base_path()`
     * n'y est pas disponible, le chemin est donc déduit du fichier.
     */
    private static function projectPath(string $relative): string
    {
        return dirname(__DIR__, 2).DIRECTORY_SEPARATOR.$relative;
    }

    /**
     * @return array<string, array{string}>
     */
    public static function modules(): array
    {
        $modules = [];

        foreach (glob(self::projectPath('Modules/*/openapi.yaml')) ?: [] as $path) {
            $slug = strtolower(basename(dirname($path)));
            $modules[$slug] = [$slug];
        }

        return $modules;
    }

    public function test_every_module_ships_a_contract(): void
    {
        $withContract = array_keys(self::modules());
        $allModules = array_map(
            static fn (string $path) => strtolower(basename($path)),
            glob(self::projectPath('Modules/*'), GLOB_ONLYDIR) ?: [],
        );

        sort($withContract);
        sort($allModules);

        $this->assertSame(
            $allModules,
            $withContract,
            'Chaque module exposant une API doit fournir un openapi.yaml.',
        );
    }

    #[DataProvider('modules')]
    public function test_the_contract_is_valid_openapi(string $module): void
    {
        $spec = $this->spec($module);

        $this->assertStringStartsWith('3.', $spec['openapi']);
        $this->assertNotEmpty($spec['info']['title']);
        $this->assertNotEmpty($spec['paths']);
    }

    #[DataProvider('modules')]
    public function test_every_route_is_documented(string $module): void
    {
        $undocumented = array_diff($this->realOperations($module), $this->documentedOperations($module));

        $this->assertSame(
            [],
            array_values($undocumented),
            "[{$module}] Ces routes existent mais ne figurent pas dans openapi.yaml :\n"
                .implode("\n", $undocumented),
        );
    }

    #[DataProvider('modules')]
    public function test_the_contract_documents_no_phantom_route(string $module): void
    {
        $phantom = array_diff($this->documentedOperations($module), $this->realOperations($module));

        $this->assertSame(
            [],
            array_values($phantom),
            "[{$module}] Ces opérations sont documentées mais n'existent pas :\n".implode("\n", $phantom),
        );
    }

    #[DataProvider('modules')]
    public function test_every_reference_resolves(string $module): void
    {
        $spec = $this->spec($module);

        // Sans UNESCAPED_SLASHES, json_encode échappe les `/` et la recherche
        // de références ne trouverait rien — le test passerait à vide.
        $encoded = (string) json_encode($spec, JSON_UNESCAPED_SLASHES);

        preg_match_all('#\#/components/(\w+)/([A-Za-z0-9_]+)#', $encoded, $matches, PREG_SET_ORDER);

        $this->assertNotEmpty($matches, "[{$module}] Le contrat ne référence aucun composant.");

        foreach ($matches as [, $section, $name]) {
            $this->assertArrayHasKey(
                $name,
                $spec['components'][$section] ?? [],
                "[{$module}] Référence cassée : #/components/{$section}/{$name}",
            );
        }
    }

    /**
     * Un code inventé dans le contrat serait tout aussi faux qu'un code inventé
     * dans le code.
     */
    #[DataProvider('modules')]
    public function test_documented_error_codes_belong_to_the_catalogue(string $module): void
    {
        $catalogue = $this->catalogueCodes();
        $encoded = (string) json_encode($this->spec($module), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        preg_match_all('/`([A-Z][A-Z0-9_]{3,})`/', $encoded, $matches);

        $unknown = array_values(array_unique(array_diff($matches[1], $catalogue)));

        $this->assertSame(
            [],
            $unknown,
            "[{$module}] Codes absents de docs/02-standards/error-codes.md : ".implode(', ', $unknown),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function spec(string $module): array
    {
        $path = self::projectPath('Modules/'.ucfirst($module).'/openapi.yaml');

        $this->assertFileExists($path);

        try {
            return Yaml::parseFile($path);
        } catch (Throwable $e) {
            $this->fail("[{$module}] YAML invalide : ".$e->getMessage());
        }
    }

    /**
     * @return list<string>
     */
    private function documentedOperations(string $module): array
    {
        $operations = [];

        foreach ($this->spec($module)['paths'] as $path => $item) {
            foreach ($item as $verb => $_) {
                if (in_array($verb, ['get', 'post', 'patch', 'put', 'delete'], true)) {
                    $operations[] = strtoupper($verb).' '.self::normalise($path);
                }
            }
        }

        sort($operations);

        return $operations;
    }

    /**
     * Les routes d'un module sont identifiées par le préfixe de leur nom, posé
     * par le ModuleServiceProvider.
     *
     * @return list<string>
     */
    private function realOperations(string $module): array
    {
        $prefix = 'api/v1';
        $operations = [];

        foreach (Router::getRoutes() as $route) {
            /** @var Route $route */
            if (! str_starts_with((string) $route->getName(), $module.'.v1.')) {
                continue;
            }

            $uri = $route->uri();

            if (! str_starts_with($uri, $prefix.'/')) {
                continue;
            }

            foreach ($route->methods() as $verb) {
                if (in_array($verb, ['GET', 'POST', 'PATCH', 'PUT', 'DELETE'], true)) {
                    $operations[] = $verb.' '.self::normalise(substr($uri, strlen($prefix)));
                }
            }
        }

        $operations = array_values(array_unique($operations));
        sort($operations);

        return $operations;
    }

    /**
     * Les noms de paramètres diffèrent légitimement entre le routeur et le
     * contrat ; leur position, non.
     */
    private static function normalise(string $path): string
    {
        return preg_replace('/\{[^}]+\}/', '{}', '/'.ltrim($path, '/'));
    }

    /**
     * @return list<string>
     */
    private function catalogueCodes(): array
    {
        $catalogue = (string) file_get_contents(self::projectPath('docs/02-standards/error-codes.md'));

        preg_match_all('/`([A-Z][A-Z0-9_]{3,})`/', $catalogue, $matches);

        return array_values(array_unique($matches[1]));
    }
}
