<?php

declare(strict_types=1);

namespace Modules\Notify\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Notify\Domain\Category;
use Modules\Notify\Domain\Channel;

final class CreateTemplateRequest extends FormRequest
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
            'key' => ['required', 'string', 'max:100', 'regex:/^[a-z0-9]+(\.[a-z0-9_]+)+$/'],
            'channel' => ['required', Rule::in(Channel::all())],

            // Ignorée si un template de plateforme porte déjà cette clé : la
            // variante en hérite, faute de quoi elle contournerait le
            // désabonnement.
            'category' => ['sometimes', Rule::in(Category::all())],

            'variables' => ['sometimes', 'array'],
            'variables.*.name' => ['required', 'string', 'max:100'],
            'variables.*.required' => ['sometimes', 'boolean'],

            'contents' => ['required', 'array', 'min:1'],
            'contents.*.locale' => ['required', 'string', 'max:10'],
            'contents.*.subject' => ['sometimes', 'nullable', 'string'],
            'contents.*.body' => ['required', 'string'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'key.regex' => 'La clé suit le format `ressource.action`, en minuscules.',
        ];
    }
}
