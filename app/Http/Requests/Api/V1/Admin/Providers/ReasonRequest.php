<?php

namespace App\Http\Requests\Api\V1\Admin\Providers;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Sensitive actions keep a reason shown to the provider, plus an optional staff-only note (BRD).
 */
class ReasonRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:1000'],
            'internal_note' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
