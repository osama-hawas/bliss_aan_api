<?php

namespace App\Http\Requests\Api\V1\Admin\Staff;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Suspend / reactivate: the reason is shown to the staff member, the note stays internal (D170).
 */
class StaffReasonRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            'internal_note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
