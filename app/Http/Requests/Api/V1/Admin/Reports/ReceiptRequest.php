<?php

namespace App\Http\Requests\Api\V1\Admin\Reports;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReceiptRequest extends FormRequest
{
    public function rules(): array
    {
        return ['lang' => ['nullable', Rule::in(config('app.supported_locales', ['ar', 'en']))]];
    }
}
