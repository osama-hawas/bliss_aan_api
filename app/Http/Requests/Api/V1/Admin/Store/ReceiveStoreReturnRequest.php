<?php

namespace App\Http\Requests\Api\V1\Admin\Store;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Mark a return received (S23, §6.3): `restock` (default true) puts the lines back in stock; optional internal note.
 */
class ReceiveStoreReturnRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'restock' => ['sometimes', 'boolean'],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }
}
