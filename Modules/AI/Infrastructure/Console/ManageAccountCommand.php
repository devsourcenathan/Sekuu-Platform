<?php

declare(strict_types=1);

namespace Modules\AI\Infrastructure\Console;

use App\Platform\Exceptions\DomainException;
use Illuminate\Console\Command;
use Modules\AI\Application\Accounts\RegisterAccount;
use Modules\AI\Domain\Models\AiAccount;
use Modules\AI\Domain\Models\AiGeneration;

/**
 * Poser une clé de la plateforme.
 *
 * ## Pourquoi ce n'est pas une route
 *
 * L'API n'administre que les comptes **d'un client** : la résolution exige que
 * l'appelant en soit propriétaire. Les nôtres portent nos identifiants et
 * servent toutes les organisations — les exposer reviendrait à confier cette
 * infrastructure à qui détient un jeton d'administration.
 *
 * C'est la règle posée pour les magasins de Storage, et elle vaut ici davantage
 * encore : un magasin fuité se lit, une clé d'IA fuitée **se dépense**.
 *
 * @see docs/03-services/ai/05-providers.md
 */
final class ManageAccountCommand extends Command
{
    protected $signature = 'ai:account
        {slug? : Nom court du compte}
        {--preset= : anthropic, openai, gemini, deepseek, groq, azure, ollama…}
        {--driver= : Pilote, si aucun prereglage}
        {--base-url= : Point d acces, si aucun prereglage}
        {--models=* : Modeles servis ; vide = ce que le pilote sait}
        {--priority=100 : Ordre d essai entre comptes de la plateforme}
        {--cap= : Plafond de depense propre au compte, en millioniemes}
        {--key= : Cle d API, sinon demandee sans echo}
        {--status= : active, paused ou disabled, sur un compte existant}
        {--from-env : Pose le compte decrit par AI_DEFAULT_*}';

    protected $description = 'Liste les comptes de la plateforme, en pose un, ou en retire un du service.';

    public function handle(RegisterAccount $register): int
    {
        if ($this->option('from-env')) {
            return $this->fromEnvironment($register);
        }

        $slug = $this->argument('slug');

        if ($slug === null) {
            return $this->list();
        }

        $existing = AiAccount::query()->where('slug', $slug)->first();

        if ($existing === null) {
            return $this->create($register, (string) $slug);
        }

        if ($this->option('status') !== null) {
            return $this->changeStatus($existing);
        }

        return $this->fix($register, $existing);
    }

    /**
     * Corriger un compte **jamais éprouvé**, à la main.
     *
     * ## Pourquoi cette porte manquait, et ce que son absence coûtait
     *
     * Une première saisie ratée — une clé tronquée, un préréglage oublié —
     * laissait une ligne `unverified` que rien ne pouvait plus corriger : la
     * commande renvoyait vers `--status`, qui ne touche pas aux identifiants, et
     * la seule voie de reprise était `--from-env`, réservée aux machines **sans**
     * shell. Sur une machine qui en a un, il fallait aller en base.
     *
     * C'est l'impasse déjà rencontrée sur Storage, reproduite ici sur le chemin
     * manuel après avoir été fermée sur le chemin automatique.
     *
     * ## Et elle reste étroite
     *
     * `repair()` ne touche qu'un compte `unverified` — il n'a jamais rien servi,
     * le corriger ne peut rien casser. Un compte **en service** ne se laisse pas
     * réécrire : une clé remplacée à la légère enverrait les générations chez un
     * fournisseur que personne n'a choisi, facturées à quelqu'un d'autre. Pour
     * celui-là, la rotation éprouve avant de remplacer.
     */
    private function fix(RegisterAccount $register, AiAccount $account): int
    {
        if ($account->status !== AiAccount::UNVERIFIED) {
            $this->error("Le compte « {$account->slug} » est en service ({$account->status}).");
            $this->newLine();
            $this->comment('--status pour le changer d\'état.');
            $this->comment('La clé se remplace par PUT /ai/accounts/{id}/credentials, qui éprouve avant de remplacer.');

            return self::FAILURE;
        }

        $models = array_values((array) $this->option('models'));

        $account = $register->repair(
            account: $account,
            preset: $this->option('preset') ?? $account->preset,
            driver: $this->option('driver') ?? ($this->option('preset') === null ? $account->driver : null),
            config: array_filter(
                ['base_url' => $this->option('base-url')],
                fn ($v): bool => $v !== null && $v !== '',
            ),
            credentials: $this->credentials(),
            models: $models === [] ? (array) $account->models : $models,
        );

        if ($account->status === AiAccount::ACTIVE) {
            $this->info("Compte « {$account->slug} » corrigé et éprouvé.");

            return self::SUCCESS;
        }

        $this->error("Épreuve échouée — {$account->verification_reason}.");
        $this->line((string) $account->verification_error);

        return self::FAILURE;
    }

    /**
     * Poser le compte depuis l'environnement, au démarrage.
     *
     * ## Pourquoi cette voie existe
     *
     * L'offre gratuite de Render n'a **pas de shell**. Sans elle, il n'existe
     * aucun moyen de poser la première clé : ni ligne de commande, ni route — et
     * une route serait précisément ce que le paragraphe ci-dessus refuse.
     *
     * Cette voie a été écrite pour Storage après qu'une mise en production a été
     * bloquée faute d'elle. La transposer coûte trente lignes ; la redécouvrir
     * coûterait un déploiement.
     *
     * ## Elle ne fait jamais échouer un démarrage
     *
     * Une clé refusée ne doit pas empêcher la plateforme de répondre :
     * l'authentification, les paiements et les notifications n'en dépendent pas.
     * La ligne reste `unverified`, et **l'épreuve quotidienne devient la
     * reprise** — le compte s'activera de lui-même le jour où la clé sera
     * corrigée, sans nouveau déploiement.
     */
    private function fromEnvironment(RegisterAccount $register): int
    {
        $slug = (string) env('AI_DEFAULT_SLUG', '');

        if ($slug === '') {
            $this->line('[ai] aucun compte déclaré dans l\'environnement.');

            return self::SUCCESS;
        }

        $existing = AiAccount::query()->where('slug', $slug)->first();

        /*
         * Un compte **qui sert** ne se laisse pas réécrire par l'environnement :
         * une variable oubliée le repointerait vers une autre clé, et les
         * générations partiraient chez un fournisseur que personne n'a choisi.
         *
         * C'est aussi le cas courant : le conteneur redémarre à chaque
         * déploiement et à chaque réveil après sommeil.
         */
        if ($existing !== null && $existing->status !== AiAccount::UNVERIFIED) {
            $this->line("[ai] compte « {$slug} » déjà posé.");

            return self::SUCCESS;
        }

        $config = array_filter([
            'base_url' => env('AI_DEFAULT_BASE_URL'),
            'auth' => env('AI_DEFAULT_AUTH'),
        ], fn ($v): bool => $v !== null && $v !== '');

        $key = (string) env('AI_DEFAULT_KEY', '');
        $credentials = $key === '' ? [] : ['api_key' => $key];

        $models = array_values(array_filter(
            array_map('trim', explode(',', (string) env('AI_DEFAULT_MODELS', ''))),
            fn (string $m): bool => $m !== '',
        ));

        try {
            /*
             * Un compte jamais éprouvé est **repris** avec la configuration
             * courante, puis remis à l'épreuve.
             *
             * Sans cela, une première tentative ratée serait définitive là où il
             * n'y a pas de shell : la ligne existerait, l'amorçage passerait son
             * chemin, et corriger une variable dans le tableau de bord n'aurait
             * aucun effet. C'est une impasse, et elle a été rencontrée.
             */
            $account = $existing !== null
                ? $register->repair(
                    account: $existing,
                    preset: env('AI_DEFAULT_PRESET'),
                    driver: env('AI_DEFAULT_DRIVER'),
                    config: $config,
                    credentials: $credentials,
                    models: $models,
                )
                : $register->handle(
                    slug: $slug,
                    preset: env('AI_DEFAULT_PRESET'),
                    driver: env('AI_DEFAULT_DRIVER'),
                    config: $config,
                    credentials: $credentials,
                    models: $models,
                    environment: app()->environment(),
                    priority: (int) env('AI_DEFAULT_PRIORITY', 10),
                );
        } catch (DomainException $e) {
            $this->error("[ai] compte « {$slug} » non posé : {$e->getMessage()}");

            return self::SUCCESS;
        }

        if ($account->status === AiAccount::ACTIVE) {
            $this->line("[ai] compte « {$slug} » posé et éprouvé.");

            return self::SUCCESS;
        }

        $this->error("[ai] compte « {$slug} » posé mais NON ÉPREUVÉ — {$account->verification_reason}.");

        /*
         * Le message brut du fournisseur, ici et nulle part ailleurs.
         *
         * Il est tenu hors des événements et des réponses d'API. Mais le journal
         * de démarrage n'est lisible que par l'exploitant, qui est précisément
         * le propriétaire de ce compte — et sans cette ligne le diagnostic ne
         * vit qu'en base, c'est-à-dire hors de portée sur une offre sans shell,
         * la seule où ce chemin sert.
         */
        foreach (preg_split('/\R/', (string) $account->verification_error) ?: [] as $row) {
            if (trim($row) !== '') {
                $this->line('[ai]   '.mb_substr(trim($row), 0, 300));
            }
        }

        $this->line('[ai] aucune génération ne partira sur ce compte. L\'épreuve est rejouée');
        $this->line('[ai] chaque nuit, et le compte s\'activera de lui-même une fois corrigé.');

        return self::SUCCESS;
    }

    private function list(): int
    {
        $accounts = AiAccount::query()->orderBy('priority')->orderBy('slug')->get();

        if ($accounts->isEmpty()) {
            $this->warn('Aucun compte. Aucune génération ne peut partir.');
            $this->newLine();
            $this->comment('Poser le premier :');
            $this->comment('  php artisan ai:account plateforme-anthropic --preset=anthropic --models=claude-haiku-4-5 --priority=10');

            return self::SUCCESS;
        }

        $this->table(
            ['Nom', 'Service', 'Env.', 'État', 'Rang', 'Clé', 'Appartient à', 'Générations'],
            $accounts->map(fn (AiAccount $c): array => [
                $c->slug,
                $c->preset ?? $c->driver,
                $c->environment,
                $c->status,
                $c->priority,
                $c->credentialFingerprint() ?? '—',
                $c->belongsToPlatform() ? 'la plateforme' : 'un client',
                AiGeneration::query()->where('account_id', $c->id)->count(),
            ])->all(),
        );

        return self::SUCCESS;
    }

    private function create(RegisterAccount $register, string $slug): int
    {
        $preset = $this->option('preset');
        $driver = $this->option('driver');

        if ($preset === null && $driver === null) {
            $this->error('Un préréglage (--preset) ou un pilote (--driver) est requis.');

            return self::FAILURE;
        }

        $config = array_filter(
            ['base_url' => $this->option('base-url')],
            fn ($v): bool => $v !== null && $v !== '',
        );

        $cap = $this->option('cap');

        try {
            $account = $register->handle(
                slug: $slug,
                preset: $preset,
                driver: $driver,
                config: $config,
                credentials: $this->credentials(),
                models: array_values((array) $this->option('models')),
                environment: app()->environment(),
                priority: (int) $this->option('priority'),
                spendCapMicros: $cap === null ? null : (int) $cap,
            );
        } catch (DomainException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($account->status === AiAccount::ACTIVE) {
            $this->info("Compte « {$account->slug} » posé et éprouvé : une génération réelle est partie et revenue.");

            return self::SUCCESS;
        }

        /*
         * L'échec de l'épreuve n'efface pas la ligne : la corriger vaut mieux
         * que la recréer, et une tentative ratée ne doit pas laisser une clé
         * déposée quelque part dont personne ne garde trace.
         */
        $this->error("Épreuve échouée — {$account->verification_reason}.");
        $this->line((string) $account->verification_error);
        $this->newLine();
        $this->comment('Le compte existe mais ne sert personne. Corrigez, puis : php artisan ai:verify '.$slug);

        return self::FAILURE;
    }

    private function changeStatus(AiAccount $account): int
    {
        $status = $this->option('status');

        if ($status === null) {
            $this->error("Le compte « {$account->slug} » existe déjà. Utilisez --status pour le changer d'état.");

            return self::FAILURE;
        }

        if (! in_array($status, [AiAccount::ACTIVE, AiAccount::PAUSED, AiAccount::DISABLED], true)) {
            $this->error('État inconnu. Attendu : active, paused ou disabled.');

            return self::FAILURE;
        }

        if ($status === AiAccount::ACTIVE && $account->verified_at === null) {
            $this->error("Ce compte n'a jamais réussi l'épreuve : il ne peut pas être activé.");

            return self::FAILURE;
        }

        $account->forceFill(['status' => $status])->save();

        $this->info("Compte « {$account->slug} » : {$status}.");

        if ($status === AiAccount::PAUSED) {
            $this->comment('On cesse d\'y appeler. L\'épreuve continue de tourner : il se relancera.');
        }

        if ($status === AiAccount::DISABLED) {
            $this->comment('Réservé au cas où la clé n\'est plus la nôtre. L\'épreuve ne le rallumera pas.');
        }

        return self::SUCCESS;
    }

    /**
     * Demandée sans écho quand elle n'est pas donnée en option.
     *
     * Une clé passée en argument entre dans l'historique du shell, et y reste
     * bien après que le terminal soit fermé.
     *
     * @return array<string, string>
     */
    private function credentials(): array
    {
        $key = $this->option('key') ?? $this->secret('Clé d\'API (laisser vide pour aucune)');

        return $key === null || $key === '' ? [] : ['api_key' => (string) $key];
    }
}
