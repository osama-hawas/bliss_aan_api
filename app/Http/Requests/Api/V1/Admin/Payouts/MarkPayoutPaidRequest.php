<?php

namespace App\Http\Requests\Api\V1\Admin\Payouts;

use Illuminate\Foundation\Http\FormRequest;

class MarkPayoutPaidRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'transfer_reference' => ['required', 'string', 'max:64', 'regex:/\S/'],
            'internal_note' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }
}
