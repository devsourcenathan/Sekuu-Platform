<?php

declare(strict_types=1);

namespace App\Platform\Support;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Socle commun à tous les modules de la plateforme.
 *
 * Chaque module enregistre lui-même ses routes, ses migrations et ses
 * traductions. Le routage est exposé sur le sous-domaine du domaine,
 * derrière le préfixe de version.
 *
 * @see docs/01-overview/architecture.md
 */
abstract class ModuleServiceProvider extends ServiceProvider
{
    /** Slug du module, utilisé pour résoudre son sous-domaine et ses traductions. */
    abstract protected function moduleSlug(): string;

    /** Chemin absolu de la racine du module. */
    abstract protected function modulePath(): string;

    public function boot(): void
    {
        $this->registerRoutes();
        $this->registerMigrations();
        $this->registerTranslations();
    }

    protected function registerRoutes(): void
    {
        $this->registerRootRoutes();

        foreach ($this->apiVersions() as $version) {
            $file = $this->modulePath().'/Routes/api_'.$version.'.php';

            if (! is_file($file)) {
                continue;
            }

            Route::domain($this->domain())
                ->prefix('api/'.$version)
                ->middleware('api')
                ->name($this->moduleSlug().'.'.$version.'.')
                ->group($file);
        }
    }

    /**
     * Routes servies à la racine du domaine, hors préfixe de version : les
     * chemins normalisés comme `/.well-known/…` ou `/health` ne peuvent pas
     * être versionnés.
     */
    protected function registerRootRoutes(): void
    {
        $file = $this->modulePath().'/Routes/root.php';

        if (! is_file($file)) {
            return;
        }

        Route::domain($this->domain())
            ->middleware('api')
            ->name($this->moduleSlug().'.')
            ->group($file);
    }

    protected function registerMigrations(): void
    {
        $path = $this->modulePath().'/Database/Migrations';

        if (is_dir($path)) {
            $this->loadMigrationsFrom($path);
        }
    }

    protected function registerTranslations(): void
    {
        $path = $this->modulePath().'/Resources/lang';

        if (is_dir($path)) {
            $this->loadTranslationsFrom($path, $this->moduleSlug());
        }
    }

    /**
     * Versions d'API exposées par le module. Deux versions majeures peuvent
     * coexister le temps d'une migration.
     *
     * @return list<string>
     */
    protected function apiVersions(): array
    {
        return ['v1'];
    }

    /**
     * Sous-domaine du module. `null` en développement : les routes répondent
     * alors sur n'importe quel hôte, ce qui évite d'imposer une configuration
     * DNS locale.
     */
    protected function domain(): ?string
    {
        $domain = config('sekuu.domains.'.$this->moduleSlug());

        return is_string($domain) && $domain !== '' ? $domain : null;
    }
}
