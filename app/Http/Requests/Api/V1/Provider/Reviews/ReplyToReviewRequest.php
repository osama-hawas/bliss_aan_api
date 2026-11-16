<?php

namespace App\Http\Requests\Api\V1\Provider\Reviews;

use Illuminate\Foundation\Http\FormRequest;

class ReplyToReviewRequest extends FormRequest
{
    public function rules(): array
    {
        return ['reply' => ['required', 'string', 'min:1', 'max:500']];
    }
}
