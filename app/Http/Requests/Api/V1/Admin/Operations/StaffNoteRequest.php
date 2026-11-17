<?php

namespace App\Http\Requests\Api\V1\Admin\Operations;

use Illuminate\Foundation\Http\FormRequest;

/**
 * An internal staff note on a request or booking (D256).
 */
class StaffNoteRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'note' => ['required', 'string', 'max:1000'],
        ];
    }
}
