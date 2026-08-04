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
     */
    public function handle(
        string $organizationId,
        string $name,
        array $scopes,
        User $creator,
        ?string $expiresAt = null,
        array $subjectTypes = [],
    ): IssuedApiKey {
        $unknown = array_values(array_diff($scopes, self::SCOPES));

        if ($unknown !== []) {
            throw DomainException::unprocessable(
                'VALIDATION_ERROR',
                __('identity::messages.api_key_unknown_scope', ['scopes' => implode(', ', $unknown)]),
            );
        }

        $subjectTypes = array_values(array_unique($subjectTypes));

        $this->guardSubjectTypes($scopes, $subjectTypes);

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
            'created_by' => $creator->id,
            'expires_at' => $expiresAt,
        ]);

        return new IssuedApiKey($key, $plainKey);
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
