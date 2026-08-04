<?php

declare(strict_types=1);

namespace Modules\Payments\Domain\Models;

use App\Platform\Support\Money;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Un prix déclaré par un produit qui ne partage pas cette base de code.
 *
 * L'analogue d'une facture, pour Sekuu Learn ou tout autre service externe :
 * l'objet dont le propriétaire nomme le prix, et que `quote()` relit en base au
 * moment de payer.
 *
 * @see docs/03-services/payments/07-external-api.md
 */
final class ExternalCharge extends Model
{
    use HasUuids;

    public const PENDING = 'pending';

    public const PAID = 'paid';

    public const FAILED = 'failed';

    /** L'agrégateur n'a jamais tranché : **on ne sait pas**, pas « échoué ». */
    public const EXPIRED = 'expired';

    protected $fillable = [
        'organization_id', 'api_key_id', 'subject_type', 'subject_id',
        'payer_type', 'payer_id', 'amount', 'currency', 'description',
        'status', 'payment_intent_id', 'settled_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'settled_at' => 'datetime',
        ];
    }

    public function money(): Money
    {
        return Money::of($this->amount, $this->currency);
    }

    public function isSettled(): bool
    {
        return in_array($this->status, [self::PAID, self::FAILED, self::EXPIRED], true);
    }
}
