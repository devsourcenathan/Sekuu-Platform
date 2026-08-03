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
        ];
    }
}
