<?php

namespace App\Http\Requests\Api\V1\Admin\Reviews;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Restore a hidden review (D261).
 */
class RestoreReviewRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ];
    }
}
