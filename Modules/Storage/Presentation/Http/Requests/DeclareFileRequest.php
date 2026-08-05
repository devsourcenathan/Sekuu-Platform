<?php

declare(strict_types=1);

namespace Modules\Storage\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * La validation borne la **forme**, jamais l'issue.
 *
 * `mime_type` et `size` sont exigés pour signer une autorisation étroite et
 * refuser tôt ce qui sera de toute façon refusé. Ils ne font pas foi : la
 * confirmation interroge le magasin et écrase ces valeurs.
 */
final class DeclareFileRequest extends FormRequest
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
            'owner_type' => ['required', 'string', 'max:64'],
            'owner_id' => ['required', 'string', 'max:64'],

            // Le nom d'origine, tel que le client l'a donné. Il ne sert jamais
            // à construire la clé de l'objet — seule son extension est reprise,
            // assainie, et dérivée du type constaté.
            'name' => ['required', 'string', 'max:255'],

            'mime_type' => ['required', 'string', 'max:128'],
            'size' => ['required', 'integer', 'min:1'],
            'destination' => ['sometimes', 'string', 'max:64'],
            'retain_days' => ['sometimes', 'integer', 'min:1', 'max:36525'],
        ];
    }
}
