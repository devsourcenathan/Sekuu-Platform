<?php

declare(strict_types=1);

namespace Modules\Notify\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class PreviewTemplateRequest extends FormRequest
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
            'locale' => ['sometimes', 'nullable', 'string', 'max:10'],
            'variables' => ['sometimes', 'array'],
        ];
    }
}
