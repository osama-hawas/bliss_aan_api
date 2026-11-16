<?php

namespace App\Http\Requests\Api\V1\Client\Cart;

use Illuminate\Foundation\Http\FormRequest;

class SetCartItemRequest extends FormRequest
{
    public function rules(): array
    {
        // No per-line maximum is approved; the variant's current stock is the only upper bound (CartService).
        return ['quantity' => ['required', 'integer', 'min:1', 'max:4294967295']];
    }
}
