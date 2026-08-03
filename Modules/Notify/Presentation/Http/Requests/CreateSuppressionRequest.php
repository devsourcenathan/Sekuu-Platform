<?php

declare(strict_types=1);

namespace Modules\Notify\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Notify\Domain\Channel;

final class CreateSuppressionRequest extends FormRequest
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
            'channel' => ['required', Rule::in(Channel::all())],
            'destination' => ['required', 'string', 'max:320'],

            // Une suppression manuelle peut être temporaire — c'est le seul
            // motif qui l'autorise. Un rebond dur, lui, est définitif.
            'expires_at' => ['sometimes', 'nullable', 'date', 'after:now'],
        ];
    }
}
