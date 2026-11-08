<?php

namespace App\Http\Requests\Api\V1\Admin\Providers;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A note to the provider, a staff-only note, or both; not neither.
 */
class AddNoteRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'note_to_provider' => ['nullable', 'required_without:internal_note', 'string', 'max:2000'],
            'internal_note' => ['nullable', 'required_without:note_to_provider', 'string', 'max:2000'],
        ];
    }
}
