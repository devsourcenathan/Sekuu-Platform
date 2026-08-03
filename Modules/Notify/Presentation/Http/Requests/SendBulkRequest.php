<?php

declare(strict_types=1);

namespace Modules\Notify\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class SendBulkRequest extends FormRequest
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
            'template_key' => ['required', 'string', 'max:100'],
            'locale' => ['sometimes', 'nullable', 'string', 'max:10'],

            'messages' => ['required', 'array', 'min:1', 'max:100'],
            'messages.*.recipient' => ['required', 'array'],
            'messages.*.recipient.email' => ['sometimes', 'nullable', 'email:rfc'],
            'messages.*.recipient.phone' => ['sometimes', 'nullable', 'string', 'regex:/^\+[1-9]\d{7,14}$/'],
            'messages.*.recipient.user_id' => ['sometimes', 'nullable', 'uuid'],
            'messages.*.variables' => ['sometimes', 'array'],
        ];
    }
}
