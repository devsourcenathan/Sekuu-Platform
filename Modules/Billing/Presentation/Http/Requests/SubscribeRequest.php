<?php

declare(strict_types=1);

namespace Modules\Billing\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class SubscribeRequest extends FormRequest
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
            'plan_key' => ['required', 'string', 'max:60'],
            'price_id' => ['sometimes', 'nullable', 'uuid'],
        ];
    }
}
