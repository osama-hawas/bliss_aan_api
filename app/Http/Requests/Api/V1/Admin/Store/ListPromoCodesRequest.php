<?php

namespace App\Http\Requests\Api\V1\Admin\Store;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Promo code list (§8.1).
 */
class ListPromoCodesRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:32'],
            'filter.state' => ['nullable', 'in:active,inactive,expired,scheduled'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ];
    }
}
