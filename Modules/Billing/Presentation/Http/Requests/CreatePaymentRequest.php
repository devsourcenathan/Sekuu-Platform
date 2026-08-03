<?php

declare(strict_types=1);

namespace Modules\Billing\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Le montant est **absent** à dessein : il vient de la facture.
 *
 * L'accepter du corps permettrait de régler 49 663 XAF avec 100 XAF — c'est
 * une faille, pas une commodité.
 *
 * L'agrégateur est absent lui aussi : c'est un détail d'exploitation, et
 * l'exposer figerait l'ordre de priorité dans les interfaces clientes.
 */
final class CreatePaymentRequest extends FormRequest
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
            'invoice_id' => ['required', 'uuid'],
            'method' => ['sometimes', 'in:mobile_money'],
            'msisdn' => ['required', 'string', 'max:20'],
        ];
    }
}
