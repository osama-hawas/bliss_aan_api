<?php

namespace App\Http\Requests\Api\V1\Admin\Catalog;

use App\Support\Money;
use Illuminate\Foundation\Http\FormRequest;

/**
 * `guide_price` sent (even null) = a guide-price preview; nothing sent = a stop preview (contract §4.4).
 */
class ServiceImpactRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'guide_price' => ['sometimes', 'nullable', 'numeric', 'decimal:0,2', 'gt:0', 'max:99999999.99'],
        ];
    }

    public function withPrice(): bool
    {
        return $this->exists('guide_price');
    }

    public function proposed(): ?string
    {
        $price = $this->validated('guide_price');

        return $price === null ? null : Money::normalize((string) $price);
    }
}
