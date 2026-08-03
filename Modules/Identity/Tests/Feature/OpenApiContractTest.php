<?php

declare(strict_types=1);

namespace Modules\Identity\Tests\Feature;

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * Le contrat OpenAPI est déclaré source de vérité par les guidelines. Sans ce
 * test, il dériverait dès la première route ajoutée sans être documentée.
 *
 * @see docs/02-standards/api-guidelines.md
 */
final class OpenApiContractTest extends TestCase
{
    private const PREFIX = 'api/v1';

    public function test_the_contract_is_valid_yaml_and_declares_openapi_3(): void
    {
        $spec = $this->spec();

        $this->assertStringStartsWith('3.', $spec['openapi']);
        $this->assertSame('Sekuu Identity', $spec['info']['title']);
    }

    public function test_every_route_is_documented(): void
    {
        $undocumented = array_diff($this->realOperations(), $this->documentedOperations());

        $this->assertSame(
            [],
            array_values($undocumented),
            "Ces routes existent mais ne figurent pas dans openapi.yaml :\n".implode("\n", $undocumented),
        );
    }

    public function test_the_contract_documents_no_phantom_route(): void
    {
        $phantom = array_diff($this->documentedOperations(), $this->realOperations());

        $this->assertSame(
            [],
            array_values($phantom),
            "Ces opérations sont documentées mais n'existent pas :\n".implode("\n", $phantom),
        );
    }

    public function test_every_reference_resolves(): void
    {
        $spec = $this->spec();

        // Sans UNESCAPED_SLASHES, json_encode échappe les `/` et la recherche
        // de références ne trouverait rien — le test passerait à vide.
        $encoded = (string) json_encode($spec, JSON_UNESCAPED_SLASHES);

        preg_match_all('#\#/components/(\w+)/([A-Za-z0-9_]+)#', $encoded, $matches, PREG_SET_ORDER);

        $this->assertNotEmpty($matches, 'Le contrat ne référence aucun composant.');

        foreach ($matches as [, $section, $name]) {
            $this->assertArrayHasKey(
                $name,
                $spec['components'][$section] ?? [],
                "Référence cassée : #/components/{$section}/{$name}",
            );
        }
    }

    /**
     * Toute erreur documentée doit exister dans le catalogue commun : un code
     * inventé dans le contrat serait tout aussi faux qu'un code inventé dans
     * le code.
     */
    public function test_documented_error_codes_belong_to_the_catalogue(): void
    {
        $catalogue = $this->catalogueCodes();
        $encoded = (string) json_encode($this->spec(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        preg_match_all('/`([A-Z][A-Z0-9_]{3,})`/', $encoded, $matches);

        $unknown = array_values(array_unique(array_diff($matches[1], $catalogue)));

        $this->assertSame(
            [],
            $unknown,
            'Codes absents de docs/02-standards/error-codes.md : '.implode(', ', $unknown),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function spec(): array
    {
        $path = base_path('Modules/Identity/openapi.yaml');

        $this->assertFileExists($path, 'Le contrat OpenAPI du module est introuvable.');

        return Yaml::parseFile($path);
    }

    /**
     * @return list<string>
     */
    private function documentedOperations(): array
    {
        $operations = [];

        foreach ($this->spec()['paths'] as $path => $item) {
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
     * @return list<string>
     */
    private function realOperations(): array
    {
        $operations = [];

        foreach (Router::getRoutes() as $route) {
            /** @var Route $route */
            $uri = $route->uri();

            if (! str_starts_with($uri, self::PREFIX.'/')) {
                continue;
            }

            $path = substr($uri, strlen(self::PREFIX));

            foreach ($route->methods() as $verb) {
                if (in_array($verb, ['GET', 'POST', 'PATCH', 'PUT', 'DELETE'], true)) {
                    $operations[] = $verb.' '.self::normalise($path);
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
        $catalogue = (string) file_get_contents(base_path('docs/02-standards/error-codes.md'));

        preg_match_all('/`([A-Z][A-Z0-9_]{3,})`/', $catalogue, $matches);

        return array_values(array_unique($matches[1]));
    }
}
