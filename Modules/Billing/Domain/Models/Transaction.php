<?php

declare(strict_types=1);

namespace Modules\Billing\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Registre **append-only**.
 *
 * Aucun `UPDATE`, aucun `DELETE` : corriger une écriture en la réécrivant
 * efface la trace de l'erreur, et avec elle toute possibilité d'expliquer un
 * solde. Un remboursement est une nouvelle ligne de signe opposé.
 *
 * @see docs/03-services/billing/02-data-model.md
 */
final class Transaction extends Model
{
    use HasUuids;

    public const CHARGE = 'charge';

    /** Commission de l'agrégateur — charge de la plateforme, pas du client. */
    public const FEE = 'fee';

    public const REFUND = 'refund';

    public const CREDIT = 'credit';

    public const DEBIT = 'debit';

    public const ADJUSTMENT = 'adjustment';

    public const UPDATED_AT = null;

    protected $fillable = [
        'organization_id', 'invoice_id', 'payment_intent_id',
        'payment_attempt_id', 'type', 'amount', 'currency', 'occurred_at',
        'description', 'metadata',
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
     * Le registre est scellé au niveau du modèle, en plus de la discipline.
     * Une contrainte qu'aucun code ne peut contourner par distraction vaut
     * mieux qu'une convention documentée.
     */
    protected static function booted(): void
    {
        self::updating(static function (): void {
            throw new RuntimeException('Le registre des transactions est append-only.');
        });

        self::deleting(static function (): void {
            throw new RuntimeException('Le registre des transactions est append-only.');
        });
    }

    /**
     * Types entrant dans le solde de crédit d'une organisation.
     *
     * `charge` et `fee` en sont exclus : le premier règle une facture, le
     * second est une charge de la plateforme. Aucun des deux n'est une somme
     * due au client.
     *
     * @return list<string>
     */
    public static function creditTypes(): array
    {
        return [self::CREDIT, self::DEBIT, self::ADJUSTMENT];
    }
}
