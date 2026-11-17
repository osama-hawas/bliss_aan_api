<?php

namespace App\Http\Requests\Api\V1\Admin\Store;

use App\Enums\StockAdjustmentReason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Set a variant's stock to a new count (D274): the count she saw (`expected_stock`) and a reason are required.
 */
class AdjustStockRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'stock' => ['required', ...VariantRules::STOCK],
            'expected_stock' => ['required', 'integer', 'min:0'],
            'reason_code' => ['required', Rule::enum(StockAdjustmentReason::class)],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }
}
