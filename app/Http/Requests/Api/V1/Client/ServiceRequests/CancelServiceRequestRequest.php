<?php

namespace App\Http\Requests\Api\V1\Client\ServiceRequests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The reason is optional free text (D67).
 */
class CancelServiceRequestRequest extends FormRequest
{
    public function rules(): array
    {
        return ['reason' => ['nullable', 'string', 'max:500']];
    }
}
