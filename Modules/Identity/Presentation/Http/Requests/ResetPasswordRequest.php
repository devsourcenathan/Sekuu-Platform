<?php

declare(strict_types=1);

namespace Modules\Identity\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Identity\Presentation\Http\Rules\StrongPassword;

final class ResetPasswordRequest extends FormRequest
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
            'token' => ['required', 'string'],
            'password' => ['required', 'string', StrongPassword::rule()],
        ];
    }
}
