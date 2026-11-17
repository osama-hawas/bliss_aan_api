<?php

namespace App\Http\Requests\Api\V1\Admin\Support;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A further chat page inside the review window (D260).
 */
class ChatPageRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'access_log_id' => ['required', 'integer'],
            'before' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ];
    }
}
