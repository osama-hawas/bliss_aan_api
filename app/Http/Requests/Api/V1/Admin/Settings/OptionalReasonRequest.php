<?php

namespace App\Http\Requests\Api\V1\Admin\Settings;

use Illuminate\Foundation\Http\FormRequest;

class OptionalReasonRequest extends FormRequest
{
    public function rules(): array
    {
        return ['reason' => ['nullable', 'string', 'max:1000']];
    }
}
