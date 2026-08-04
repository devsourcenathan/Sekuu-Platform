<?php

declare(strict_types=1);

namespace Modules\Payments\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Registre de caisse, **append-only**.
 *
 * Ne porte que l'argent réellement encaissé ou rendu. Le crédit commercial
 * d'une organisation vit dans [CreditEntry], côté facturation : les deux
 * étaient colocalisés dans une même table `transactions`, alors que le code
 * distinguait déjà les deux jeux de types — `creditTypes()` excluait exactement
 * `charge` et `fee`.
 *
 * Aucun `UPDATE`, aucun `DELETE` : corriger une écriture en la réécrivant
 * efface la trace de l'erreur, et avec elle toute possibilité d'expliquer un
 * solde. Un remboursement est une nouvelle ligne de signe opposé.
 *
 * @see docs/03-services/billing/02-data-model.md
 */
final class PaymentTransaction extends Model
{
    use HasUuids;

    public const CHARGE = 'charge';

    /** Commission de l'agrégateur — charge de la plateforme, pas du client. */
    public const FEE = 'fee';

    /** Remboursement effectif : l'argent quitte le compte marchand. */
    public const REFUND = 'refund';

    public const UPDATED_AT = null;

    protected $fillable = [
        'payment_intent_id', 'payment_attempt_id', 'subject_type', 'subject_id',
        'payee_organization_id', 'type', 'amount', 'currency', 'occurred_at',
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
            throw new RuntimeException('Le registre de caisse est append-only.');
        });

        self::deleting(static function (): void {
            throw new RuntimeException('Le registre de caisse est append-only.');
        });
    }
}
