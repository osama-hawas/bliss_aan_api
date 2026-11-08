<?php

namespace App\Http\Requests\Api\V1\Provider\Setup;

use Illuminate\Foundation\Http\FormRequest;

class AddServiceRequest extends FormRequest
{
    public function rules(): array
    {
        return ['service_id' => ['required', 'integer']];
    }
}
