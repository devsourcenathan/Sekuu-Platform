<?php

declare(strict_types=1);

namespace Modules\AI\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Une clé chez un fournisseur.
 *
 * Une ligne en base, et non une variable d'environnement : plusieurs comptes
 * par fournisseur, un client peut apporter le sien, et une clé compromise se
 * remplace sans redéployer.
 *
 * @see docs/04-decisions/adr-0017-ai-accounts.md
 */
final class AiAccount extends Model
{
    use HasUuids;

    protected $table = 'ai_accounts';

    public const UNVERIFIED = 'unverified';

    public const ACTIVE = 'active';

    /** Suspendu : on cesse d'y appeler, on le relancera. */
    public const PAUSED = 'paused';

    /** Le compte n'est plus le nôtre. On ne le relancera pas. */
    public const DISABLED = 'disabled';

    protected $fillable = [
        'slug', 'driver', 'preset', 'config', 'credentials', 'models',
        'owner_organization_id', 'owner_api_key_id', 'environment', 'status',
        'spend_cap_micros', 'priority', 'verified_at', 'verification_reason',
        'verification_error',
    ];

    /** Jamais rendus par l'API — pas même à celui qui les a déposés. */
    protected $hidden = ['credentials'];

    protected function casts(): array
    {
        return [
            'config' => 'array',
            'credentials' => 'encrypted:array',
            'models' => 'array',
            'spend_cap_micros' => 'integer',
            'priority' => 'integer',
            'verified_at' => 'datetime',
        ];
    }

    /**
     * Il n'y a pas d'équivalent au `read_only` de Storage, et c'est révélateur :
     * un magasin garde des octets qu'il faut continuer à servir, un compte d'IA
     * ne garde rien. Cesser d'y appeler suffit à le retirer.
     */
    public function canGenerate(): bool
    {
        return $this->status === self::ACTIVE;
    }

    public function belongsToPlatform(): bool
    {
        return $this->owner_organization_id === null && $this->owner_api_key_id === null;
    }

    /**
     * Sur le compte d'un tiers, notre coût est une **estimation** : son tarif
     * négocié, son engagement de volume ou sa région donnent un autre montant.
     */
    public function costIsEstimated(): bool
    {
        return ! $this->belongsToPlatform();
    }

    public function baseUrl(): ?string
    {
        return $this->config['base_url'] ?? null;
    }

    public function apiKey(): ?string
    {
        return $this->credentials['api_key'] ?? null;
    }

    /**
     * De quoi reconnaître une clé sans pouvoir s'en servir.
     */
    public function credentialFingerprint(): ?string
    {
        $key = $this->apiKey();

        if ($key === null || $key === '') {
            return null;
        }

        return '…'.mb_substr($key, -4).'/'.mb_substr(hash('sha256', $key), 0, 8);
    }
}
