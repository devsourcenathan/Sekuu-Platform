<?php

declare(strict_types=1);

namespace Modules\Notify\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Ni la clé, ni le canal, ni la catégorie ne sont modifiables : les
     * changer reviendrait à créer un autre template, et la catégorie
     * gouverne le droit au désabonnement.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'variables' => ['sometimes', 'array'],
            'variables.*.name' => ['required', 'string', 'max:100'],
            'variables.*.required' => ['sometimes', 'boolean'],

            'status' => ['sometimes', Rule::in(['active', 'archived'])],

            'contents' => ['sometimes', 'array', 'min:1'],
            'contents.*.locale' => ['required', 'string', 'max:10'],
            'contents.*.subject' => ['sometimes', 'nullable', 'string'],
            'contents.*.body' => ['required', 'string'],
        ];
    }
}
