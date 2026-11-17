<?php

namespace App\Http\Requests\Api\V1\Admin\Support;

use App\Enums\TicketStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A staff status move (D258). The service requires the reason to close or reopen.
 */
class TicketStatusRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::enum(TicketStatus::class)],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }
}
