<?php

declare(strict_types=1);

namespace Modules\Storage\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Un magasin où des octets peuvent être posés.
 *
 * Une ligne en base, et non une entrée de configuration : plusieurs comptes par
 * fournisseur, un produit peut apporter le sien, et l'ajout ne demande pas de
 * déploiement.
 *
 * @see docs/04-decisions/adr-0014-storage-destinations.md
 */
final class Destination extends Model
{
    use HasUuids;

    protected $table = 'storage_destinations';

    /** Jamais éprouvée, ou retombée en échec. La résolution l'ignore. */
    public const UNVERIFIED = 'unverified';

    public const ACTIVE = 'active';

    /** On cesse d'y écrire, on continue d'y lire. */
    public const READ_ONLY = 'read_only';

    /** Le compte n'est plus le nôtre : ni écriture, ni lecture. */
    public const DISABLED = 'disabled';

    protected $fillable = [
        'slug', 'driver', 'preset', 'config', 'credentials',
        'owner_organization_id', 'owner_api_key_id', 'environment',
        'status', 'is_default', 'verified_at', 'verification_reason',
        'verification_error',
    ];

    /**
     * `credentials` est chiffré par la couche de chiffrement de Laravel, et
     * n'est **jamais** rendu par l'API — pas même à celui qui l'a déposé, qui
     * ne reçoit qu'une empreinte.
     */
    protected $hidden = ['credentials'];

    protected function casts(): array
    {
        return [
            'config' => 'array',
            'credentials' => 'encrypted:array',
            'is_default' => 'boolean',
            'verified_at' => 'datetime',
        ];
    }

    /**
     * Peut-on y poser de nouveaux octets ?
     *
     * `read_only` répond non, et c'est l'état qui compte : on retire une
     * destination du service en cessant d'y écrire, jamais en coupant la
     * lecture. Les fichiers déjà posés y sont, et le resteront.
     */
    public function acceptsWrites(): bool
    {
        return $this->status === self::ACTIVE;
    }

    public function allowsReads(): bool
    {
        return in_array($this->status, [self::ACTIVE, self::READ_ONLY], true);
    }

    public function belongsToPlatform(): bool
    {
        return $this->owner_organization_id === null && $this->owner_api_key_id === null;
    }

    public function prefix(): string
    {
        $prefix = (string) ($this->config['prefix'] ?? '');

        return $prefix === '' ? '' : rtrim($prefix, '/').'/';
    }

    /**
     * De quoi reconnaître une clé sans pouvoir s'en servir.
     *
     * Quatre derniers caractères et un condensat court. Assez pour qu'un
     * opérateur vérifie qu'il regarde la bonne clé, jamais assez pour la
     * rejouer.
     */
    public function credentialFingerprint(): ?string
    {
        $credentials = $this->credentials;

        if (! is_array($credentials) || $credentials === []) {
            return null;
        }

        $reference = (string) ($credentials['key'] ?? reset($credentials));

        if ($reference === '') {
            return null;
        }

        return '…'.mb_substr($reference, -4).'/'.mb_substr(hash('sha256', $reference), 0, 8);
    }
}
