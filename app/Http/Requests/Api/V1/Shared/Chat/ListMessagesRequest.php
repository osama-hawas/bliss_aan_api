<?php

namespace App\Http\Requests\Api\V1\Shared\Chat;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Cursor pagination (`03-api-conventions.md`): `before` = an older-than message id, `per_page` ≤ 50.
 */
class ListMessagesRequest extends FormRequest
{
    public function rules(): array
    {
        return ['before' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:50']];
    }
}
