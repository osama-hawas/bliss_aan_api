<?php

namespace App\Http\Requests\Api\V1\Admin\Operations;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A staff intervention (D256): the reason is required and shown to the parties; the internal note is staff only.
 */
class InterventionRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
            'internal_note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
