<?php

declare(strict_types=1);

namespace Modules\Notify\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class SendNotificationRequest extends FormRequest
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
            'scheduled_for' => ['sometimes', 'nullable', 'date', 'after:now'],

            // L'appelant fournit les coordonnées dont il dispose ; ce sont les
            // templates qui déterminent lesquelles servent.
            'recipient' => ['required', 'array'],
            'recipient.email' => ['sometimes', 'nullable', 'email:rfc'],
            'recipient.phone' => ['sometimes', 'nullable', 'string', 'regex:/^\+[1-9]\d{7,14}$/'],
            'recipient.user_id' => ['sometimes', 'nullable', 'uuid'],

            'variables' => ['sometimes', 'array'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'recipient.phone.regex' => 'Le numéro doit être au format E.164, par exemple +237690000000.',
        ];
    }
}
