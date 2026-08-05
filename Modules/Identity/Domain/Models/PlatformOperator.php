<?php

declare(strict_types=1);

namespace Modules\Identity\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un utilisateur habilité à agir au nom de Sekuu.
 *
 * @see docs/04-decisions/adr-0018-platform-operator.md
 */
final class PlatformOperator extends Model
{
    use HasUuids;

    /** Lire et modifier le catalogue de plans et ses limites. */
    public const PLANS = 'platform.plans';

    /** Lister les organisations, leur état, leur usage. */
    public const ORGANIZATIONS = 'platform.organizations';

    /** Consulter abonnements et factures d'un client. */
    public const BILLING = 'platform.billing';

    public const AUDIT = 'platform.audit';

    /**
     * Octroyer des permissions.
     *
     * **Délibérément inerte** : aucune route ne l'honore. Elle existe pour être
     * refusée — une permission qui distribue des permissions transformerait le
     * premier compte compromis en un nombre illimité de comptes compromis.
     */
    public const OPERATORS = 'platform.operators';

    /**
     * Il n'y a **pas** de `platform.infrastructure`.
     *
     * L'état des magasins et des agrégateurs est déjà rendu par
     * `GET /storage/health` et `GET /payments/health` — sans secret, et sans
     * shell, ce qui était le besoin réel. Une route d'agrégation les
     * dupliquerait et forcerait un module à en importer deux autres pour un
     * confort.
     *
     * Une permission qui ne garde rien est pire qu'absente : quelqu'un la lira
     * en base et croira qu'elle agit.
     *
     * @var list<string>
     */
    public const ALL = [
        self::PLANS, self::ORGANIZATIONS, self::BILLING,
        self::AUDIT, self::OPERATORS,
    ];

    protected $fillable = ['user_id', 'permissions', 'granted_by', 'granted_at', 'revoked_at'];

    protected function casts(): array
    {
        return [
            'permissions' => 'array',
            'granted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null;
    }

    /**
     * Une permission absente est refusée.
     *
     * Jamais de jeton « toutes les permissions » : un opérateur créé avant
     * qu'une permission n'existe ne doit rien gagner du fait qu'elle existe.
     * C'est la règle déjà posée pour la liste blanche des clés d'API.
     */
    public function may(string $permission): bool
    {
        return $this->isActive()
            && $permission !== self::OPERATORS
            && in_array($permission, (array) $this->permissions, true);
    }
}
