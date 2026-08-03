<?php

declare(strict_types=1);

namespace Modules\Identity\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Identity\Presentation\Http\Rules\StrongPassword;

final class AcceptInvitationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Les champs d'inscription ne sont requis que si aucun compte n'existe
     * encore pour l'adresse invitée — ce que seul le cas d'usage peut savoir.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'first_name' => ['sometimes', 'string', 'max:100'],
            'last_name' => ['sometimes', 'string', 'max:100'],
            'password' => ['sometimes', 'string', StrongPassword::rule()],
        ];
    }
}
