<?php

declare(strict_types=1);

namespace Modules\AI\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Où livrer l'issue d'une génération, et avec quel secret la signer.
 *
 * @see docs/03-services/ai/07-external-api.md
 */
final class AiEndpoint extends Model
{
    use HasUuids;

    public const ACTIVE = 'active';

    /** Suspendu. Les livraisons s'accumulent, elles ne se perdent pas. */
    public const PAUSED = 'paused';

    protected $fillable = [
        'organization_id', 'url', 'secret',
        'previous_secret', 'previous_secret_expires_at', 'status',
    ];

    /** Un secret lisible dans une réponse d'API serait un secret partagé. */
    protected $hidden = ['secret', 'previous_secret'];

    protected function casts(): array
    {
        return ['previous_secret_expires_at' => 'datetime'];
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(AiDelivery::class);
    }

    /**
     * Les secrets avec lesquels signer, le courant d'abord.
     *
     * @return list<string>
     */
    public function signingSecrets(): array
    {
        $secrets = [$this->secret];

        if ($this->previous_secret !== null
            && $this->previous_secret_expires_at !== null
            && $this->previous_secret_expires_at->isFuture()) {
            $secrets[] = $this->previous_secret;
        }

        return $secrets;
    }
}
