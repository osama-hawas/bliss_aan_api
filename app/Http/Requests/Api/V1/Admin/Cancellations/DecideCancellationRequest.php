<?php

namespace App\Http\Requests\Api\V1\Admin\Cancellations;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Admin decision (D131): accept or reject, a reason both parties see, an optional internal note.
 */
class DecideCancellationRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'decision' => ['required', 'string', 'in:accept,reject'],
            'decision_reason' => ['required', 'string', 'min:1', 'max:1000'],
            'internal_note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
