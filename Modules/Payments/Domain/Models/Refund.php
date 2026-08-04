<?php

declare(strict_types=1);

namespace Modules\Payments\Domain\Models;

use App\Platform\Support\Money;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un remboursement décidé, avec son propre cycle de vie.
 *
 * Distinct de la ligne `refund` du registre de caisse, qui n'est écrite qu'au
 * décaissement **constaté** : ici on porte l'intention et son état, là-bas le
 * fait comptable.
 *
 * @see docs/03-services/payments/08-refunds.md
 */
final class Refund extends Model
{
    use HasUuids;

    /** Décidé, l'argent n'est pas encore sorti. */
    public const PENDING = 'pending';

    /** Décaissement en cours chez un agrégateur. */
    public const PROCESSING = 'processing';

    /** L'argent est sorti, et le registre le dit. */
    public const SUCCEEDED = 'succeeded';

    public const FAILED = 'failed';

    /** Annulé avant tout décaissement. Aucun argent n'a bougé. */
    public const CANCELLED = 'cancelled';

    /**
     * États qui immobilisent une part du brut encaissé.
     *
     * `failed` n'en fait pas partie : un décaissement qui a échoué n'a rien
     * rendu, et la somme redevient remboursable. `cancelled` non plus.
     *
     * @var list<string>
     */
    public const HOLDS_FUNDS = [self::PENDING, self::PROCESSING, self::SUCCEEDED];

    protected $fillable = [
        'payment_intent_id', 'subject_type', 'subject_id', 'amount', 'currency',
        'reason', 'status', 'provider', 'provider_ref', 'failure_code',
        'failure_reason', 'requested_by', 'requested_via', 'idempotency_key',
        'settled_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'settled_at' => 'datetime',
        ];
    }

    public function intent(): BelongsTo
    {
        return $this->belongsTo(PaymentIntent::class, 'payment_intent_id');
    }

    public function money(): Money
    {
        return Money::of($this->amount, $this->currency);
    }

    public function isSettled(): bool
    {
        return in_array($this->status, [self::SUCCEEDED, self::FAILED, self::CANCELLED], true);
    }
}
