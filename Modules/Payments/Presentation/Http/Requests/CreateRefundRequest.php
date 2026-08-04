<?php

declare(strict_types=1);

namespace Modules\Payments\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Demander à rendre l'argent.
 *
 * `amount` est **facultatif** : l'absence signifie « tout ce qui reste ». C'est
 * le cas le plus courant, et obliger le produit à calculer lui-même le reliquat
 * l'exposerait à se tromper — il tiendrait alors une seconde comptabilité, qui
 * finirait par diverger de celle de la plateforme.
 *
 * @see docs/03-services/payments/08-refunds.md
 */
final class CreateRefundRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Entier, dans l'unité la plus petite de la devise. Absent = le
            // reliquat entier.
            'amount' => ['sometimes', 'integer', 'min:1'],

            // Obligatoire. Un remboursement est un geste dont quelqu'un devra
            // rendre compte ; un motif facultatif serait vide neuf fois sur dix,
            // et la dixième est celle qu'on cherchera un an plus tard.
            'reason' => ['required', 'string', 'max:255'],
        ];
    }
}
