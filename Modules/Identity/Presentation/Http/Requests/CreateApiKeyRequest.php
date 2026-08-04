<?php

declare(strict_types=1);

namespace Modules\Identity\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Identity\Application\ApiKeys\IssueApiKey;

final class CreateApiKeyRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:200'],
            'scopes' => ['required', 'array', 'min:1'],
            'scopes.*' => [Rule::in(IssueApiKey::SCOPES)],
            'expires_at' => ['sometimes', 'nullable', 'date', 'after:now'],

            // Le périmètre d'objets qu'un scope de paiement autorise à faire
            // payer. Le format `{module}.{ressource}` est celui des événements
            // de domaine : une seule convention pour toute la plateforme.
            'subject_types' => ['sometimes', 'array'],
            'subject_types.*' => ['string', 'max:40', 'regex:/^[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*$/'],
        ];
    }
}
