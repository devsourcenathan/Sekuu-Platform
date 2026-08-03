<?php

declare(strict_types=1);

namespace Modules\Identity\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class AddWorkspaceMemberRequest extends FormRequest
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
        // Pas de règle `exists` : l'appartenance à l'organisation est vérifiée
        // par le cas d'usage, qui répond sans révéler l'existence du membership.
        return [
            'membership_id' => ['required', 'uuid'],
        ];
    }
}
