<?php

declare(strict_types=1);

namespace Modules\Payments\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Le seul endroit de la plateforme où un montant traverse HTTP.
 *
 * Il y est borné par ce que la clé d'API autorise — un `subject_type` de son
 * allowlist, jamais une facture d'abonnement — et par le fait que cette clé est
 * strictement serveur à serveur. Exposée à un client final, elle permettrait de
 * déclarer n'importe quel prix sur n'importe quel objet du produit.
 *
 * @see docs/03-services/payments/07-external-api.md
 */
final class CreateChargeRequest extends FormRequest
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
            // `{module}.{ressource}`, la convention des événements de domaine.
            'subject_type' => ['required', 'string', 'max:40', 'regex:/^[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*$/'],

            // UUID imposé : `payment_intents.subject_id` en est un, et un
            // identifiant applicatif quelconque n'y entrerait pas.
            'subject_id' => ['required', 'uuid'],

            'payer_type' => ['required', 'string', 'max:40', 'regex:/^[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*$/'],
            'payer_id' => ['required', 'uuid'],

            // Entier, dans l'unité la plus petite de la devise. Le franc CFA
            // n'a pas de centime : 45 000 XAF s'écrit `45000`.
            'amount' => ['required', 'integer', 'min:1'],
            'currency' => ['required', 'string', 'size:3'],

            // Reprise telle quelle dans le libellé vu par le client sur son
            // téléphone : c'est le produit qui sait comment se nommer.
            'description' => ['required', 'string', 'max:255'],

            'msisdn' => ['required', 'string', 'max:20'],
        ];
    }
}
