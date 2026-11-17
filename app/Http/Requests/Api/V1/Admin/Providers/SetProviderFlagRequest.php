<?php

namespace App\Http\Requests\Api\V1\Admin\Providers;

use Illuminate\Foundation\Http\FormRequest;

/**
 * D175: `{value, reason, internal_note?}` for the verification badge and the featured flag.
 */
class SetProviderFlagRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'value' => ['required', 'boolean'],
            'reason' => ['required', 'string', 'max:1000'],
            'internal_note' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
