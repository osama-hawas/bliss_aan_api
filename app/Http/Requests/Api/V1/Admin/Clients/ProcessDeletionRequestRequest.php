<?php

namespace App\Http\Requests\Api\V1\Admin\Clients;

use Illuminate\Foundation\Http\FormRequest;

/**
 * S22 (D336): staff "process now" always carries a reason (audit row + the request's internal note).
 */
class ProcessDeletionRequestRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:1000'],
        ];
    }
}
