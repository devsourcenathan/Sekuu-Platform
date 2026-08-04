<?php

declare(strict_types=1);

namespace Modules\Identity\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @see docs/02-standards/security.md
 */
final class ApiKey extends Model
{
    use HasUuids;

    protected $fillable = [
        'organization_id',
        'name',
        'prefix',
        'key_hash',
        'scopes',
        'subject_types',
        'created_by',
        'expires_at',
    ];

    /** La valeur en clair n'existe qu'à la création, elle n'est jamais relue. */
    protected $hidden = ['key_hash'];

    protected function casts(): array
    {
        return [
            'scopes' => 'array',
            'subject_types' => 'array',
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public static function hash(string $plainKey): string
    {
        return hash('sha256', $plainKey);
    }

    /**
     * Une clé jamais utilisée depuis un an mérite d'être repérée : le champ
     * `last_used_at` sert à repérer les clés dormantes.
     */
    public function isUsable(): bool
    {
        return $this->revoked_at === null
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    public function hasScope(string $scope): bool
    {
        return in_array($scope, (array) $this->scopes, true);
    }

    /**
     * Cette clé peut-elle faire payer ce type d'objet ?
     *
     * `null` signifie **aucun**, jamais « tous ». Une clé émise avant que ce
     * périmètre n'existe ne doit rien gagner du fait qu'il existe ; et une clé
     * dont l'allowlist a été vidée doit cesser d'encaisser, pas se retrouver
     * habilitée partout.
     */
    public function allowsSubjectType(string $subjectType): bool
    {
        return in_array($subjectType, (array) ($this->subject_types ?? []), true);
    }
}
