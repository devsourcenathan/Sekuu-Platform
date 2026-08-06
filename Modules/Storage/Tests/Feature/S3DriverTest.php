<?php

declare(strict_types=1);

namespace Modules\Storage\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;
use Modules\Storage\Application\Destinations\RegisterDestination;
use Modules\Storage\Domain\Models\Destination;
use Modules\Storage\Infrastructure\Drivers\DriverRegistry;
use Modules\Storage\Infrastructure\Drivers\UploadIntent;
use Tests\TestCase;

/**
 * Le pilote S3 se construit et signe — **sans réseau**.
 *
 * ## Pourquoi ce test existe
 *
 * Il n'existait pas, et son absence a coûté un déploiement.
 *
 * Toute la suite s'appuyait sur le pilote local, seul magasin utilisable hors
 * ligne. Le pilote S3 — celui qui sert AWS, R2, B2, Scaleway et MinIO, donc la
 * totalité de la production — n'était **jamais instancié**. Son adaptateur
 * Flysystem manquait des dépendances, et rien ne pouvait le dire : ni les 561
 * tests, ni une relecture.
 *
 * Le premier démarrage en production a répondu `unreachable`, la catégorie par
 * défaut du classificateur, sur une erreur qui n'avait rien d'un problème de
 * réseau : `Class "League\Flysystem\AwsS3V3\PortableVisibilityConverter" not
 * found`.
 *
 * ## Ce qu'il vérifie, et ce qu'il ne peut pas vérifier
 *
 * Il construit le disque et **signe** une URL : deux opérations purement
 * locales — dérivation HMAC, aucun appel sortant. C'est exactement le chemin
 * qui manquait.
 *
 * Il ne prouve rien sur un vrai compte : ni les identifiants, ni les
 * permissions, ni l'existence du compartiment. C'est le rôle de l'épreuve, qui
 * elle exige le réseau et un vrai fournisseur.
 *
 * @see docs/03-services/storage/06-destinations.md
 */
final class S3DriverTest extends TestCase
{
    use RefreshDatabase;

    /**
     * L'assertion la plus banale de la suite, et celle qui aurait tout évité.
     */
    public function test_the_flysystem_adapter_is_installed(): void
    {
        $this->assertTrue(
            class_exists(AwsS3V3Adapter::class),
            'league/flysystem-aws-s3-v3 est requis par le pilote S3, donc par toute la production.',
        );
    }

    public function test_the_driver_signs_a_read_url_without_touching_the_network(): void
    {
        $destination = $this->s3();

        $url = app(DriverRegistry::class)->for($destination)->readUrl($destination, 'org/2026/08/objet.png', 600);

        $this->assertStringContainsString('sekuu-test.compte.r2.cloudflarestorage.com', $url);
        $this->assertStringContainsString('X-Amz-Signature=', $url);
        // Pas d'égalité stricte : le SDK décompte le temps écoulé entre le
        // calcul de l'échéance et la signature, et rend 599 aussi souvent que
        // 600. Ce qui compte est que l'autorisation soit **courte**.
        preg_match('/X-Amz-Expires=(\d+)/', $url, $m);
        $this->assertGreaterThan(590, (int) ($m[1] ?? 0));
        $this->assertLessThanOrEqual(600, (int) ($m[1] ?? 0));
    }

    public function test_the_driver_signs_an_upload_ticket(): void
    {
        $destination = $this->s3();

        $ticket = app(DriverRegistry::class)->for($destination)->uploadTicket(
            $destination,
            'org/2026/08/objet.png',
            new UploadIntent('image/png', 1024, 900),
        );

        $this->assertSame('PUT', $ticket->method);
        $this->assertStringContainsString('X-Amz-Signature=', $ticket->url);

        // Le `Content-Type` est couvert par la signature : le magasin refuse
        // lui-même ce qui n'y correspond pas.
        $this->assertSame('image/png', $ticket->headers['Content-Type']);
    }

    /**
     * Le préréglage `r2` construit son point d'accès à partir du compte, et
     * c'est de la donnée — pas du code.
     */
    public function test_the_r2_preset_builds_its_endpoint_from_the_account(): void
    {
        $destination = $this->s3();

        $this->assertSame(
            'https://compte.r2.cloudflarestorage.com',
            $destination->config['endpoint'],
        );
        $this->assertSame('auto', $destination->config['region']);
    }

    /**
     * Enregistré sans épreuve : elle exige le réseau, et ce test n'en veut pas.
     */
    private function s3(): Destination
    {
        [$driver, $config] = (function (): array {
            $mirror = new \ReflectionMethod(RegisterDestination::class, 'applyPreset');

            return $mirror->invoke(app(RegisterDestination::class), 'r2', null, [
                'bucket' => 'sekuu-test',
                'account_id' => 'compte',
            ]);
        })();

        return Destination::query()->create([
            'slug' => 's3-hors-ligne',
            'driver' => $driver,
            'preset' => 'r2',
            'config' => $config,
            'credentials' => ['key' => 'AKIAEXAMPLE7X2Q', 'secret' => 'secret-de-test'],
            'environment' => app()->environment(),
            'status' => Destination::ACTIVE,
            'verified_at' => now(),
        ]);
    }
}
