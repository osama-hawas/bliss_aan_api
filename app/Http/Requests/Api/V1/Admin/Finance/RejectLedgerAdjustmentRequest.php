<?php

namespace App\Http\Requests\Api\V1\Admin\Finance;

use Illuminate\Foundation\Http\FormRequest;

class RejectLedgerAdjustmentRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ];
    }
}
