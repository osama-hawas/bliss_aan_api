<?php

namespace App\Http\Requests\Api\V1\Admin\Store;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Staff action with an optional internal note.
 */
class NoteRequest extends FormRequest
{
    public function rules(): array
    {
        return ['note' => ['nullable', 'string', 'max:500']];
    }
}
