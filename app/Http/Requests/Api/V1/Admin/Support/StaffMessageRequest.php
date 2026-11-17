<?php

namespace App\Http\Requests\Api\V1\Admin\Support;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A staff reply or internal note, deduplicated by `client_uuid` (12b pattern).
 */
class StaffMessageRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:1000'],
            'client_uuid' => ['required', 'uuid'],
        ];
    }
}
