<?php

declare(strict_types=1);

namespace Modules\AI\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Une exécution, de sa demande à son issue.
 *
 * Sans ce registre, la facture d'un fournisseur en fin de mois est un nombre que
 * personne ne sait expliquer ni imputer.
 *
 * @see docs/03-services/ai/02-data-model.md
 */
final class AiGeneration extends Model
{
    use HasUuids;

    protected $table = 'ai_generations';

    public const QUEUED = 'queued';

    public const RUNNING = 'running';

    public const SUCCEEDED = 'succeeded';

    /** Aucune sortie exploitable — **et le coût peut être non nul**. */
    public const FAILED = 'failed';

    /** Arrêtée avant tout appel. */
    public const CANCELLED = 'cancelled';

    protected $fillable = [
        'organization_id', 'task', 'status', 'account_id', 'provider', 'model',
        'input_hash', 'input_tokens', 'output_tokens', 'cost_micros',
        'cost_estimated', 'latency_ms', 'attempts', 'failure_code',
        'failure_reason', 'requested_by', 'requested_via', 'idempotency_key',
        'retain_until', 'started_at', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'cost_micros' => 'integer',
            'cost_estimated' => 'boolean',
            'latency_ms' => 'integer',
            'attempts' => 'integer',
            'retain_until' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(AiAccount::class, 'account_id');
    }

    public function content(): BelongsTo
    {
        return $this->belongsTo(AiContent::class, 'id', 'generation_id');
    }

    public function isSettled(): bool
    {
        return in_array($this->status, [self::SUCCEEDED, self::FAILED, self::CANCELLED], true);
    }

    /**
     * Normalise avant de hacher : deux demandes identiques à un retour à la
     * ligne près ne doivent pas produire deux facturations.
     */
    public static function hash(string $input): string
    {
        return hash('sha256', trim((string) preg_replace('/\s+/u', ' ', $input)));
    }
}
