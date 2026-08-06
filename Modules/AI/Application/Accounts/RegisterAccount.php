<?php

declare(strict_types=1);

namespace Modules\AI\Application\Accounts;

use App\Platform\Exceptions\DomainException;
use Modules\AI\Domain\Models\AiAccount;
use Modules\AI\Infrastructure\Drivers\DriverRegistry;

/**
 * Poser une clé, et l'éprouver aussitôt.
 *
 * @see docs/03-services/ai/05-providers.md
 */
final class RegisterAccount
{
    public function __construct(
        private readonly DriverRegistry $drivers,
        private readonly VerifyAccount $verifier,
    ) {}

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $credentials
     * @param  list<string>  $models
     */
    public function handle(
        string $slug,
        ?string $preset,
        ?string $driver,
        array $config,
        array $credentials,
        array $models,
        string $environment,
        ?string $organizationId = null,
        ?string $apiKeyId = null,
        int $priority = 100,
        ?int $spendCapMicros = null,
    ): AiAccount {
        $this->guardEnvironment($environment);

        [$driver, $config] = $this->applyPreset($preset, $driver, $config);

        // Le pilote doit exister **avant** l'écriture : une ligne pointant vers
        // un pilote absent serait un compte qu'on ne peut ni éprouver ni
        // retirer proprement.
        $this->drivers->get($driver);

        if (AiAccount::query()->where('slug', $slug)->exists()) {
            throw DomainException::conflict(
                'AI_ACCOUNT_IN_USE',
                __('ai::messages.slug_taken', ['slug' => $slug]),
            );
        }

        $account = AiAccount::query()->create([
            'slug' => $slug,
            'driver' => $driver,
            'preset' => $preset,
            'config' => $config,
            'credentials' => $credentials === [] ? null : $credentials,
            'models' => $models === [] ? null : $models,
            'owner_organization_id' => $organizationId,
            'owner_api_key_id' => $apiKeyId,
            'environment' => $environment,
            'status' => AiAccount::UNVERIFIED,
            'priority' => $priority,
            'spend_cap_micros' => $spendCapMicros,
        ]);

        // Une clé fausse découverte ici coûte deux minutes ; découverte au
        // premier appel d'un client, un incident — et sur une tâche synchrone,
        // un incident visible par son utilisateur final.
        $this->verifier->handle($account);

        return $account->refresh();
    }

    /**
     * Remplacer les identifiants d'un compte **en service**, sans le couper.
     *
     * L'épreuve porte sur la nouvelle clé **avant** que l'ancienne soit
     * abandonnée : une rotation ratée ne doit pas mettre hors service un compte
     * qui fonctionnait. C'est la seule voie par laquelle un compte actif se
     * laisse réécrire, et elle ne touche qu'aux identifiants.
     *
     * @param  array<string, mixed>  $credentials
     */
    public function rotate(AiAccount $account, array $credentials): AiAccount
    {
        $previous = $account->credentials;
        $state = $account->status;

        $account->forceFill(['credentials' => $credentials])->save();

        if ($this->verifier->handle($account)) {
            return $account->refresh();
        }

        // L'épreuve a échoué : elle a déjà écrit une raison et, le cas échéant,
        // sorti le compte du service. On remet ce qui marchait, y compris
        // l'état, et on le dit franchement à l'appelant.
        $account->forceFill([
            'credentials' => $previous,
            'status' => $state,
            'verification_reason' => null,
            'verification_error' => null,
        ])->save();

        throw DomainException::unprocessable(
            'AI_ACCOUNT_UNVERIFIED',
            __('ai::messages.rotation_rejected'),
        );
    }

    /**
     * Réappliquer une configuration à un compte **jamais éprouvé**, puis le
     * remettre à l'épreuve.
     *
     * ## Pourquoi cette porte existe, et pourquoi elle est étroite
     *
     * Un compte est une donnée, pas une configuration : il ne se laisse pas
     * réécrire par l'environnement, sans quoi une variable oubliée repointerait
     * un compte en service vers une autre clé — et les générations partiraient
     * chez un fournisseur que personne n'a choisi, facturées à quelqu'un
     * d'autre.
     *
     * Mais un compte `unverified` n'a **jamais rien servi**. Le corriger ne peut
     * rien casser, et sans cette porte une première tentative ratée serait
     * définitive là où il n'y a pas de shell : la ligne existerait, l'amorçage
     * la verrait et passerait son chemin, et aucune correction de variable
     * n'aurait d'effet.
     *
     * D'où la règle, reprise mot pour mot de Storage où elle a été apprise à
     * ses dépens : **l'environnement amorce, et répare ce qui n'a jamais servi.
     * Il ne touche jamais à un compte qui fonctionne.**
     *
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $credentials
     * @param  list<string>  $models
     */
    public function repair(
        AiAccount $account,
        ?string $preset,
        ?string $driver,
        array $config,
        array $credentials,
        array $models,
    ): AiAccount {
        if ($account->status !== AiAccount::UNVERIFIED) {
            return $account;
        }

        [$driver, $config] = $this->applyPreset($preset, $driver, $config);

        $this->drivers->get($driver);

        $account->forceFill([
            'driver' => $driver,
            'preset' => $preset,
            'config' => $config,
            'credentials' => $credentials === [] ? null : $credentials,
            'models' => $models === [] ? null : $models,
        ])->save();

        $this->verifier->handle($account);

        return $account->refresh();
    }

    /**
     * Le garde-fou d'environnement, dans les deux sens et sans échappatoire.
     *
     * Une clé de production sur un poste de développement facture de vrais
     * appels à chaque exécution de la suite de tests, et personne ne le voit
     * avant la facture. Une clé de test en production rend un service qui a
     * l'air de marcher.
     *
     * C'est la même faute que `CredentialGuard` empêche côté paiement.
     */
    private function guardEnvironment(string $environment): void
    {
        if ($environment !== app()->environment()) {
            throw DomainException::unprocessable(
                'AI_ACCOUNT_FORBIDDEN',
                __('ai::messages.environment_mismatch', [
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
     * Les valeurs fournies par l'appelant **l'emportent** — c'est ce qui permet
     * à un client de pointer un serveur compatible que nous n'avons jamais vu
     * sans qu'on ajoute une ligne pour lui.
     *
     * @param  array<string, mixed>  $config
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function applyPreset(?string $preset, ?string $driver, array $config): array
    {
        if ($preset === null) {
            if ($driver === null) {
                throw DomainException::unprocessable(
                    'AI_DRIVER_UNKNOWN',
                    __('ai::messages.driver_or_preset_required'),
                );
            }

            return [$driver, $config];
        }

        $definition = (array) config('ai.presets.'.$preset, []);

        if ($definition === []) {
            throw DomainException::unprocessable(
                'AI_DRIVER_UNKNOWN',
                __('ai::messages.preset_unknown', ['preset' => $preset]),
            );
        }

        foreach ((array) ($definition['requires'] ?? []) as $required) {
            if (! isset($config[$required]) || $config[$required] === '') {
                throw DomainException::unprocessable(
                    'AI_ACCOUNT_UNVERIFIED',
                    __('ai::messages.preset_requires', ['preset' => $preset, 'field' => $required]),
                );
            }
        }

        $merged = $config + array_diff_key($definition, array_flip(['driver', 'requires']));

        return [$driver ?? (string) $definition['driver'], $merged];
    }
}
