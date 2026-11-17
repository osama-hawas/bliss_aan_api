<?php

namespace App\Http\Requests\Api\V1\Admin\Payouts;

use Illuminate\Foundation\Http\FormRequest;

class RejectPayoutRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:1000', 'regex:/\S/'],
            'internal_note' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }
}
