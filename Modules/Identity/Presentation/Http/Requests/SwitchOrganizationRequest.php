<?php

declare(strict_types=1);

namespace Modules\Identity\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class SwitchOrganizationRequest extends FormRequest
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
        // Pas de règle `exists` : l'appartenance est vérifiée par le cas
        // d'usage, qui répond 404 sans révéler l'existence de l'organisation.
        return [
            'organization_id' => ['required', 'uuid'],
        ];
    }
}
