<?php

namespace App\Http\Requests\Api\V1\Shared\Chat;

use Illuminate\Foundation\Http\FormRequest;

class MarkReadRequest extends FormRequest
{
    public function rules(): array
    {
        return ['message_id' => ['required', 'integer', 'min:1']];
    }
}
