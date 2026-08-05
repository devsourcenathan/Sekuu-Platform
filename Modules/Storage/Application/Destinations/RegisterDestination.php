<?php

declare(strict_types=1);

namespace Modules\Storage\Application\Destinations;

use App\Platform\Exceptions\DomainException;
use Modules\Storage\Domain\Models\Destination;
use Modules\Storage\Infrastructure\Drivers\DriverRegistry;

/**
 * Enregistrer un magasin, et l'éprouver aussitôt.
 *
 * @see docs/03-services/storage/06-destinations.md
 */
final class RegisterDestination
{
    public function __construct(
        private readonly DriverRegistry $drivers,
        private readonly VerifyDestination $verifier,
    ) {}

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $credentials
     */
    public function handle(
        string $slug,
        ?string $preset,
        ?string $driver,
        array $config,
        array $credentials,
        string $environment,
        ?string $organizationId = null,
        ?string $apiKeyId = null,
        bool $isDefault = false,
    ): Destination {
        $this->guardEnvironment($environment);

        [$driver, $config] = $this->applyPreset($preset, $driver, $config);

        // Le pilote doit exister **avant** l'écriture : une ligne pointant vers
        // un pilote absent serait une destination qu'on ne peut ni éprouver ni
        // supprimer proprement.
        $this->drivers->get($driver);

        if (Destination::query()->where('slug', $slug)->exists()) {
            throw DomainException::conflict(
                'STORAGE_DESTINATION_IN_USE',
                __('storage::messages.slug_taken', ['slug' => $slug]),
            );
        }

        $destination = Destination::query()->create([
            'slug' => $slug,
            'driver' => $driver,
            'preset' => $preset,
            'config' => $config,
            'credentials' => $credentials === [] ? null : $credentials,
            'owner_organization_id' => $organizationId,
            'owner_api_key_id' => $apiKeyId,
            'environment' => $environment,
            'status' => Destination::UNVERIFIED,
            'is_default' => $isDefault,
        ]);

        // Des identifiants faux découverts ici coûtent deux minutes ; découverts
        // au premier téléversement d'un client, un incident.
        $this->verifier->handle($destination);

        return $destination->refresh();
    }

    /**
     * Réappliquer une configuration à un magasin **jamais éprouvé**, puis le
     * remettre à l'épreuve.
     *
     * ## Pourquoi cette porte existe, et pourquoi elle est étroite
     *
     * Une destination est une donnée, pas une configuration : elle ne se laisse
     * pas réécrire par l'environnement, sans quoi une variable oubliée
     * repointerait un magasin en service vers un autre compte — et les fichiers
     * déjà posés deviendraient introuvables, sans erreur.
     *
     * Mais un magasin `unverified` n'a **jamais rien porté**. Le corriger ne
     * peut rien casser, et sans cette porte une première tentative ratée serait
     * définitive là où il n'y a pas de shell : la ligne existerait, l'amorçage
     * la verrait et passerait son chemin, et aucune correction de variable
     * n'aurait d'effet.
     *
     * D'où la règle : **l'environnement amorce, et répare ce qui n'a jamais
     * servi. Il ne touche jamais à un magasin qui fonctionne.**
     */
    public function repair(
        Destination $destination,
        ?string $preset,
        ?string $driver,
        array $config,
        array $credentials,
    ): Destination {
        if ($destination->status !== Destination::UNVERIFIED) {
            return $destination;
        }

        [$driver, $config] = $this->applyPreset($preset, $driver, $config);

        $this->drivers->get($driver);

        $destination->forceFill([
            'driver' => $driver,
            'preset' => $preset,
            'config' => $config,
            'credentials' => $credentials === [] ? null : $credentials,
        ])->save();

        $this->verifier->handle($destination);

        return $destination->refresh();
    }

    /**
     * Le garde-fou d'environnement, sans échappatoire.
     *
     * Un environnement de recette pointé sur le compartiment de production y
     * écrirait sans une erreur, et le balayage des orphelins y effacerait de
     * vrais fichiers. C'est la même faute que `CredentialGuard` empêche côté
     * paiement, sur une ressource où elle est irréversible.
     */
    private function guardEnvironment(string $environment): void
    {
        if ($environment !== app()->environment()) {
            throw DomainException::unprocessable(
                'STORAGE_DESTINATION_FORBIDDEN',
                __('storage::messages.environment_mismatch', [
                    'declared' => $environment,
                    'running' => app()->environment(),
                ]),
            );
        }
    }

    /**
     * Un préréglage n'est que de la donnée : il complète la configuration, il
     * ne la remplace pas.
     *
     * Les valeurs fournies par l'appelant **l'emportent** — un préréglage est
     * un défaut commode, jamais une contrainte, et une destination peut s'en
     * passer complètement en donnant son point d'accès à la main.
     *
     * @param  array<string, mixed>  $config
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function applyPreset(?string $preset, ?string $driver, array $config): array
    {
        if ($preset === null) {
            if ($driver === null) {
                throw DomainException::unprocessable(
                    'STORAGE_DRIVER_UNKNOWN',
                    __('storage::messages.driver_or_preset_required'),
                );
            }

            return [$driver, $config];
        }

        $definition = (array) config('storage.presets.'.$preset, []);

        if ($definition === []) {
            throw DomainException::unprocessable(
                'STORAGE_DRIVER_UNKNOWN',
                __('storage::messages.preset_unknown', ['preset' => $preset]),
            );
        }

        foreach ((array) ($definition['requires'] ?? []) as $required) {
            if (! isset($config[$required]) || $config[$required] === '') {
                throw DomainException::unprocessable(
                    'STORAGE_DESTINATION_UNVERIFIED',
                    __('storage::messages.preset_requires', ['preset' => $preset, 'field' => $required]),
                );
            }
        }

        $merged = $config + array_diff_key($definition, array_flip(['driver', 'requires']));

        if (isset($merged['endpoint']) && is_string($merged['endpoint'])) {
            $merged['endpoint'] = $this->interpolate($merged['endpoint'], $merged);
        }

        return [$driver ?? (string) $definition['driver'], $merged];
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function interpolate(string $template, array $values): string
    {
        foreach ($values as $key => $value) {
            if (is_scalar($value)) {
                $template = str_replace('{'.$key.'}', (string) $value, $template);
            }
        }

        return $template;
    }
}
