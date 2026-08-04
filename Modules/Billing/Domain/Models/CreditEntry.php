<?php

declare(strict_types=1);

namespace Modules\Billing\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Registre de crédit commercial d'une organisation, **append-only**.
 *
 * Naît d'une proration ou d'un avoir, jamais d'un remboursement en espèces —
 * lent, coûteux et souvent manuel en Mobile Money.
 *
 * Le solde n'est **jamais stocké** : il est la somme des lignes. Un solde
 * stocké et un registre finissent par diverger, et c'est alors le registre qui
 * a raison.
 *
 * @see docs/04-decisions/adr-0007-mobile-money-prepaid-subscriptions.md
 */
final class CreditEntry extends Model
{
    use HasUuids;

    public const CREDIT = 'credit';

    public const DEBIT = 'debit';

    /** Correction manuelle, toujours motivée. */
    public const ADJUSTMENT = 'adjustment';

    public const UPDATED_AT = null;

    protected $fillable = [
        'organization_id', 'invoice_id', 'payment_intent_id', 'type',
        'amount', 'currency', 'occurred_at', 'description', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'metadata' => 'array',
            'occurred_at' => 'immutable_datetime',
        ];
    }

    /**
     * Append-only est une propriété du registre, pas du module : elle doit donc
     * être répliquée ici, et non héritée du registre de caisse.
     */
    protected static function booted(): void
    {
        self::updating(static function (): void {
            throw new RuntimeException('Le registre de crédit est append-only.');
        });

        self::deleting(static function (): void {
            throw new RuntimeException('Le registre de crédit est append-only.');
        });
    }
}
