<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Identity\Application\ApiKeys\IssueApiKey;
use Tests\TestCase;

/**
 * Contrôles d'architecture exécutables.
 *
 * Les règles de `docs/01-overview/architecture.md` — un module ne lit jamais
 * les tables d'un autre, aucune clé étrangère inter-modules — n'étaient jusqu'ici
 * garanties que par la discipline. Six mois après une extraction, un `use`
 * réintroduit ne se voit dans aucune revue.
 *
 * ## Pourquoi une liste d'exceptions plutôt qu'un échec sec
 *
 * Les violations connues sont **énumérées**, et le test vérifie l'égalité avec
 * cette liste. Trois conséquences :
 *
 *  - la dette est chiffrée, visible, et diminue à mesure qu'on la paye ;
 *  - une violation **nouvelle** échoue immédiatement ;
 *  - la suite reste verte pendant le chantier, donc une régression sur autre
 *    chose reste détectable.
 *
 * Un test rouge pendant trois étapes ne protège plus de rien : on cesse de le
 * regarder.
 */
final class ArchitectureTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Clés étrangères connues entre les tables de paiement et celles de
     * facturation.
     *
     * **Vide depuis l'étape 3 de l'extraction.** Les trois qui existaient —
     * `payment_intents.invoice_id`, et les deux liens de `transactions` — ont
     * été remplacées par des références logiques. Toute réapparition fait
     * échouer ce test.
     *
     * @see docs/05-analyses/extraction-payments.md
     *
     * @var list<string>
     */
    private const DETTE_CLES_ETRANGERES = [];

    /** Tables appartenant à la couche de paiement. */
    private const TABLES_PAIEMENT = [
        'payment_intents',
        'payment_attempts',
        'provider_events',
        'payment_transactions',
    ];

    /** Tables appartenant à la facturation. */
    private const TABLES_FACTURATION = [
        'invoices',
        'invoice_lines',
        'subscriptions',
        'plans',
        'plan_prices',
        'plan_products',
    ];

    /**
     * Une clé étrangère entre deux modules empêche de les déployer séparément,
     * et impose un ordre de migration que rien ne documente.
     */
    public function test_cross_module_foreign_keys_match_the_known_debt(): void
    {
        $actuelles = $this->foreignKeysBetween(self::TABLES_PAIEMENT, self::TABLES_FACTURATION);

        // Le registre de crédit reste côté facturation : ses liens vers les
        // tables de paiement seraient inter-modules, et sa référence à la
        // facture doit rester logique pour la même raison.
        $actuelles = array_values(array_unique(array_merge(
            $actuelles,
            $this->foreignKeysBetween(['credit_entries'], ['payment_intents', 'payment_attempts', 'invoices']),
        )));

        sort($actuelles);

        $connues = self::DETTE_CLES_ETRANGERES;
        sort($connues);

        $this->assertSame(
            $connues,
            $actuelles,
            "Les clés étrangères inter-modules ont changé.\n"
            ."Si vous en avez supprimé une, retirez-la de DETTE_CLES_ETRANGERES.\n"
            .'Si vous en avez ajouté une, ne le faites pas : utilisez une référence logique.',
        );
    }

    /**
     * Chaque module doit avoir une entrée de sous-domaine.
     *
     * `ModuleServiceProvider::domain()` lit `config('sekuu.domains.{slug}')`. Une
     * entrée manquante ne produit **aucune erreur** : la valeur est `null`, la
     * contrainte d'hôte est simplement désactivée, et le module répond partout.
     *
     * C'est exactement ce qui est arrivé à Payments après son extraction de
     * Billing : `SEKUU_DOMAIN_PAYMENTS` n'avait aucun effet, et
     * `payments.sekuu.com` — documenté dans l'OpenAPI et la mise en service —
     * ne se serait jamais lié.
     */
    public function test_every_module_has_a_subdomain_entry(): void
    {
        $slugs = [];

        foreach (glob(base_path('Modules/*/[A-Z]*ServiceProvider.php')) as $fichier) {
            if (preg_match("/moduleSlug\(\): string\s*\{\s*return '([a-z]+)'/", (string) file_get_contents($fichier), $trouve) === 1) {
                $slugs[] = $trouve[1];
            }
        }

        sort($slugs);

        $this->assertNotEmpty($slugs, 'Aucun module trouvé : le motif de détection a changé.');

        $manquants = array_values(array_diff($slugs, array_keys((array) config('sekuu.domains'))));

        $this->assertSame(
            [],
            $manquants,
            "Ces modules n'ont pas d'entrée dans config/sekuu.php : leur sous-domaine "
            ."serait ignoré sans qu'aucune erreur ne le signale.",
        );
    }

    /**
     * Le verrou qui empêche la re-fusion.
     *
     * Vide tant que `Modules/Payments` n'existe pas — c'est voulu : la règle
     * doit être en place **avant** le premier fichier, pas après.
     */
    /**
     * **Tout scope qu'un module oppose à un appelant doit être émissible.**
     *
     * ## Le défaut que ce test attrape, et qui existait vraiment
     *
     * Storage définissait `storage.write`, `storage.read` et
     * `storage.destinations` dans son contrôleur, les documentait, et les
     * exigeait à chaque appel. Aucun des trois ne figurait dans
     * `IssueApiKey::SCOPES` : la liste y est fermée, donc **aucune clé de
     * Storage n'était émissible par l'API**.
     *
     * Rien ne l'a signalé, parce que les tests de Storage écrivent leurs clés
     * directement en base — ils vérifiaient donc le contrôleur en contournant
     * précisément la voie qui était cassée. C'est la même forme que le pilote S3
     * jamais instancié : un chemin éprouvé, et le vrai chemin à côté.
     *
     * Le contrôle porte sur les constantes des traits `Resolves*Actor`, qui sont
     * l'endroit unique où chaque module déclare ses scopes.
     */
    public function test_every_scope_a_module_demands_can_actually_be_issued(): void
    {
        $declared = [];

        foreach (glob(base_path('Modules/*/Presentation/Http/Concerns/Resolves*Actor.php')) ?: [] as $file) {
            preg_match_all(
                "/const\s+[A-Z_]+\s*=\s*'([a-z]+\.[a-z_]+)'/",
                (string) file_get_contents($file),
                $matches,
            );

            foreach ($matches[1] as $scope) {
                $declared[$scope] = basename($file);
            }
        }

        $this->assertNotSame([], $declared, 'Aucun scope trouvé : le motif de recherche a dû dériver.');

        $unissuable = array_diff_key($declared, array_flip(IssueApiKey::SCOPES));

        $this->assertSame(
            [],
            $unissuable,
            'Scopes exigés par un contrôleur mais absents de IssueApiKey::SCOPES : '
            .implode(', ', array_keys($unissuable)),
        );
    }

    public function test_payments_never_references_billing(): void
    {
        $this->assertSame(
            [],
            $this->filesReferencing(base_path('Modules/Payments'), 'Modules\\Billing\\'),
            'Le module Payments doit rester ignorant de Billing : passer par un contrat.',
        );
    }

    /**
     * L'inverse est autorisé par un contrat, jamais par un accès direct au
     * modèle Eloquent.
     *
     * **Le code de test est exclu, délibérément.** Un test de Billing qui règle
     * une facture puis vérifie l'intention de paiement produite éprouve la
     * couture entre les deux modules — c'est précisément son travail, et le lui
     * interdire le forcerait à n'observer que des effets indirects. La règle
     * protège le code de production, où un accès direct crée une dépendance
     * qu'aucun déploiement ne pourra séparer.
     */
    public function test_billing_production_code_never_touches_payment_models(): void
    {
        $interdits = $this->filesReferencing(
            base_path('Modules/Billing'),
            'Modules\\Payments\\Domain\\Models\\',
            exclureTests: true,
        );

        $this->assertSame(
            [],
            $interdits,
            'Billing doit passer par le contrat de Payments, pas par ses modèles.',
        );
    }

    /**
     * @param  list<string>  $depuis
     * @param  list<string>  $vers
     * @return list<string>
     */
    private function foreignKeysBetween(array $depuis, array $vers): array
    {
        $lignes = DB::select(
            "SELECT tc.table_name AS source,
                    kcu.column_name AS colonne,
                    ccu.table_name AS cible
             FROM information_schema.table_constraints tc
             JOIN information_schema.key_column_usage kcu
               ON tc.constraint_name = kcu.constraint_name
             JOIN information_schema.constraint_column_usage ccu
               ON tc.constraint_name = ccu.constraint_name
             WHERE tc.constraint_type = 'FOREIGN KEY'
               AND tc.table_name = ANY(?)
               AND ccu.table_name = ANY(?)",
            ['{'.implode(',', $depuis).'}', '{'.implode(',', $vers).'}'],
        );

        return array_map(
            static fn (object $r): string => $r->source.'.'.$r->colonne.' -> '.$r->cible,
            $lignes,
        );
    }

    /**
     * @return list<string>
     */
    private function filesReferencing(string $racine, string $aiguille, bool $exclureTests = false): array
    {
        if (! is_dir($racine)) {
            return [];
        }

        $coupables = [];

        $fichiers = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($racine));

        foreach ($fichiers as $fichier) {
            if (! $fichier->isFile() || $fichier->getExtension() !== 'php') {
                continue;
            }

            if ($exclureTests && str_contains($fichier->getPathname(), DIRECTORY_SEPARATOR.'Tests'.DIRECTORY_SEPARATOR)) {
                continue;
            }

            if (str_contains((string) file_get_contents($fichier->getPathname()), $aiguille)) {
                $coupables[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $fichier->getPathname());
            }
        }

        sort($coupables);

        return $coupables;
    }
}
