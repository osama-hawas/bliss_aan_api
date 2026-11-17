<?php

namespace App\Http\Requests\Api\V1\Admin\Store;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Stop needs a reason; activate takes an optional one (D276). Both may carry a staff-only note.
 */
class StopRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'reason' => [str_ends_with($this->path(), '/stop') ? 'required' : 'nullable', 'string', 'min:3', 'max:500'],
            'internal_note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
