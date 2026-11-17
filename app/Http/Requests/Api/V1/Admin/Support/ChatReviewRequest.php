<?php

namespace App\Http\Requests\Api\V1\Admin\Support;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Open a chat review with a reason (Req 42, D260).
 */
class ChatReviewRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            'before' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ];
    }
}
