<?php

namespace App\Http\Requests\Api\V1\Admin\Reviews;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Hide a review (D261).
 */
class HideReviewRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            'internal_note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
