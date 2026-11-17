<?php

namespace App\Http\Requests\Api\V1\Admin\Catalog;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Stopping a catalog row always needs a reason (D181); the internal note is staff-only.
 */
class StopCatalogItemRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            'internal_note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
