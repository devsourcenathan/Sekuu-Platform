<?php

declare(strict_types=1);

namespace Modules\Notify\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Notify\Domain\Category;
use Modules\Notify\Domain\Channel;

final class UpdatePreferencesRequest extends FormRequest
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
            'preferences' => ['required', 'array', 'min:1'],
            'preferences.*.category' => ['required', Rule::in(Category::all())],
            'preferences.*.channel' => ['required', Rule::in(Channel::all())],
            'preferences.*.enabled' => ['required', 'boolean'],
        ];
    }
}
