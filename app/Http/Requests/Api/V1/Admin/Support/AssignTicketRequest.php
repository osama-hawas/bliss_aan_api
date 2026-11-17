<?php

namespace App\Http\Requests\Api\V1\Admin\Support;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Assign or unassign (`assignee_id` null) a ticket (D259).
 */
class AssignTicketRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'assignee_id' => ['present', 'nullable', 'integer'],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }
}
