<?php

namespace App\Http\Requests\Api\V1\Provider\Setup;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `media_id` comes from `POST shared/uploads` with context `portfolio`.
 */
class AddPortfolioRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'media_id' => ['required', 'integer'],
            'caption' => ['nullable', 'string', 'max:200'],
        ];
    }
}
