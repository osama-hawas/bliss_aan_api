<?php

namespace App\Http\Requests\Api\V1\Shared\Support;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Her reply (D160, D162): deduplicated by `client_uuid`.
 */
class ReplySupportTicketRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:1000'],
            'client_uuid' => ['required', 'uuid'],
        ];
    }
}
