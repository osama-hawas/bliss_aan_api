<?php

namespace App\Http\Requests\Api\V1\Admin\Providers;

use Illuminate\Foundation\Http\FormRequest;

/**
 * What the provider must complete (at least one item) and why.
 */
class RequestCompletionRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1', 'max:20'],
            'items.*' => ['required', 'string', 'max:300'],
            'reason' => ['required', 'string', 'max:1000'],
            'internal_note' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
