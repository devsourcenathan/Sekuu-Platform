<?php

declare(strict_types=1);

namespace Modules\Payments\Domain\Models;

use App\Platform\Support\Money;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Ce que le client veut payer — à distinguer de ce qu'on a tenté, et chez qui.
 *
 * L'intention ne sait pas ce qu'elle règle : elle porte un `subject_type` et un
 * `subject_id` qu'elle n'interprète jamais. C'est ce qui permet à une facture
 * d'abonnement et à une inscription à une formation d'emprunter le même chemin.
 *
 * @see docs/04-decisions/adr-0008-payment-aggregators-failover.md
 */
final class PaymentIntent extends Model
{
    use HasUuids;

    public const PENDING = 'pending';

    public const PROCESSING = 'processing';

    public const SUCCEEDED = 'succeeded';

    public const FAILED = 'failed';

    /** **On ne sait pas** — ce qui n'est pas « cela a échoué ». */
    public const EXPIRED = 'expired';

    public const CANCELLED = 'cancelled';

    /** Le payeur est une organisation cliente : abonnement, facture. */
    public const PAYER_ORGANIZATION = 'identity.organization';

    /** Le payeur est une personne, qui achète pour son propre compte. */
    public const PAYER_USER = 'identity.user';

    protected $fillable = [
        'subject_type', 'subject_id', 'payer_type', 'payer_id',
        'payee_organization_id', 'amount', 'currency', 'method',
        'operator', 'msisdn', 'status', 'failure_code', 'failure_reason',
        'idempotency_key', 'expires_at', 'initiated_by', 'request_id',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'expires_at' => 'immutable_datetime',
        ];
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(PaymentAttempt::class)->orderBy('priority');
    }

    public function money(): Money
    {
        return Money::of($this->amount, $this->currency);
    }

    public function isSettled(): bool
    {
        return in_array($this->status, [self::SUCCEEDED, self::FAILED, self::EXPIRED, self::CANCELLED], true);
    }

    /**
     * Organisation à laquelle rattacher un événement de domaine.
     *
     * Le payeur quand c'en est une, le bénéficiaire sinon. Un apprenant qui
     * paie une formation n'est pas une organisation ; l'événement doit tout de
     * même rester rattachable au centre qui encaisse.
     */
    public function contextOrganizationId(): ?string
    {
        return $this->payer_type === self::PAYER_ORGANIZATION
            ? $this->payer_id
            : $this->payee_organization_id;
    }
}
