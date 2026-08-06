<?php

declare(strict_types=1);

namespace Modules\Identity\Application\ApiKeys;

use App\Platform\Exceptions\DomainException;
use Illuminate\Support\Str;
use Modules\Identity\Domain\Models\ApiKey;
use Modules\Identity\Domain\Models\User;

final class IssueApiKey
{
    /**
     * Scopes qu'une clé peut porter. Une clé ne peut jamais obtenir un droit
     * qui n'existe pas ici : la liste est fermée.
     *
     * @var list<string>
     */
    public const SCOPES = [
        'notifications.send',
        'notifications.read',
        'notifications.manage',
        'payments.charge',
        'payments.read',
        'payments.refund',

        /*
         * Storage. Trois droits distincts : déposer, lire, et enregistrer ses
         * propres magasins. Une clé habilitée à déposer ne doit pas pouvoir
         * enregistrer un magasin — ce sont deux dangers différents, et un seul
         * droit pour les deux serait le plus large des deux.
         *
         * Ils manquaient à cette liste, et le défaut ne se voyait pas : les
         * tests de Storage écrivent leurs clés directement en base. Aucune clé
         * de Storage n'était donc émissible par l'API. Voir le test
         * d'architecture qui empêche désormais qu'un scope existe côté
         * contrôleur sans exister ici.
         */
        'storage.write',
        'storage.read',
        'storage.destinations',

        // AI. Même découpage, même raison.
        'ai.run',
        'ai.read',
        'ai.accounts',
    ];

    /**
     * Scopes qui n'ont aucun sens sans un périmètre d'objets.
     *
     * `payments.charge` autorise à **déclarer un prix**. Le porter sans dire sur
     * quoi reviendrait à pouvoir en déclarer un sur n'importe quel objet de la
     * plateforme, factures d'abonnement comprises.
     *
     * @var list<string>
     */
    public const SCOPES_REQUIRING_SUBJECT_TYPES = [
        'payments.charge',
        'payments.read',
        'payments.refund',
        'storage.write',
        'storage.read',
    ];

    /**
     * Scopes qui n'ont aucun sens sans une liste de tâches.
     *
     * `ai.run` autorise à **dépenser**. Le porter sans dire sur quelles tâches
     * reviendrait à pouvoir lancer la plus chère du catalogue en boucle — et une
     * tâche ajoutée demain habiliterait rétroactivement toutes les clés
     * existantes.
     *
     * @var list<string>
     */
    public const SCOPES_REQUIRING_AI_TASKS = [
        'ai.run',
    ];

    /**
     * Types que jamais aucune clé ne peut porter.
     *
     * Une facture est le seul objet dont Sekuu est à la fois vendeur et
     * plateforme : son prix est produit par Billing, en base. Autoriser une clé
     * à le déclarer rouvrirait exactement le trou que tout ce module ferme —
     * régler 49 663 XAF avec 100 XAF.
     *
     * @var list<string>
     */
    public const SUBJECT_TYPES_RESERVED = [
        'billing.invoice',
    ];

    /**
     * @param  list<string>  $scopes
     * @param  list<string>  $subjectTypes
     * @param  list<string>  $aiTasks
     */
    public function handle(
        string $organizationId,
        string $name,
        array $scopes,
        User $creator,
        ?string $expiresAt = null,
        array $subjectTypes = [],
        array $aiTasks = [],
    ): IssuedApiKey {
        $unknown = array_values(array_diff($scopes, self::SCOPES));

        if ($unknown !== []) {
            throw DomainException::unprocessable(
                'VALIDATION_ERROR',
                __('identity::messages.api_key_unknown_scope', ['scopes' => implode(', ', $unknown)]),
            );
        }

        $subjectTypes = array_values(array_unique($subjectTypes));
        $aiTasks = array_values(array_unique($aiTasks));

        $this->guardSubjectTypes($scopes, $subjectTypes);
        $this->guardAiTasks($scopes, $aiTasks);

        // `sk_live_` en production, `sk_test_` ailleurs : une clé de test
        // utilisée par erreur en production doit être reconnaissable d'un coup
        // d'œil, sans avoir à la chercher en base.
        $prefix = app()->environment('production') ? 'sk_live_' : 'sk_test_';
        $plainKey = $prefix.Str::random(48);

        $key = ApiKey::create([
            'organization_id' => $organizationId,
            'name' => $name,
            'prefix' => $prefix.substr(explode($prefix, $plainKey)[1], 0, 4),
            'key_hash' => ApiKey::hash($plainKey),
            'scopes' => array_values($scopes),
            'subject_types' => $subjectTypes === [] ? null : $subjectTypes,
            'ai_tasks' => $aiTasks === [] ? null : $aiTasks,
            'created_by' => $creator->id,
            'expires_at' => $expiresAt,
        ]);

        return new IssuedApiKey($key, $plainKey);
    }

    /**
     * Une clé qui exécute doit dire quoi, et une liste sans le scope est une
     * erreur de saisie qu'il vaut mieux ne pas taire.
     *
     * @param  list<string>  $scopes
     * @param  list<string>  $aiTasks
     */
    private function guardAiTasks(array $scopes, array $aiTasks): void
    {
        $needsPerimeter = array_intersect($scopes, self::SCOPES_REQUIRING_AI_TASKS) !== [];

        if ($needsPerimeter && $aiTasks === []) {
            throw DomainException::unprocessable(
                'VALIDATION_ERROR',
                __('identity::messages.api_key_ai_tasks_required'),
            );
        }

        if (! $needsPerimeter && $aiTasks !== []) {
            throw DomainException::unprocessable(
                'VALIDATION_ERROR',
                __('identity::messages.api_key_ai_tasks_unused'),
            );
        }

        $unknown = array_values(array_diff($aiTasks, array_keys((array) config('ai.tasks', []))));

        if ($unknown !== []) {
            throw DomainException::unprocessable(
                'VALIDATION_ERROR',
                __('identity::messages.api_key_unknown_task', ['tasks' => implode(', ', $unknown)]),
            );
        }
    }

    /**
     * @param  list<string>  $scopes
     * @param  list<string>  $subjectTypes
     */
    private function guardSubjectTypes(array $scopes, array $subjectTypes): void
    {
        $reserved = array_values(array_intersect($subjectTypes, self::SUBJECT_TYPES_RESERVED));

        if ($reserved !== []) {
            throw DomainException::unprocessable(
                'VALIDATION_ERROR',
                __('identity::messages.api_key_reserved_subject_type', ['types' => implode(', ', $reserved)]),
            );
        }

        $needsPerimeter = array_intersect($scopes, self::SCOPES_REQUIRING_SUBJECT_TYPES) !== [];

        if ($needsPerimeter && $subjectTypes === []) {
            throw DomainException::unprocessable(
                'VALIDATION_ERROR',
                __('identity::messages.api_key_subject_types_required'),
            );
        }

        // L'inverse aussi : un périmètre sans le scope correspondant est une
        // erreur de saisie, et la taire laisserait croire à une habilitation.
        if (! $needsPerimeter && $subjectTypes !== []) {
            throw DomainException::unprocessable(
                'VALIDATION_ERROR',
                __('identity::messages.api_key_subject_types_unused'),
            );
        }
    }
}
