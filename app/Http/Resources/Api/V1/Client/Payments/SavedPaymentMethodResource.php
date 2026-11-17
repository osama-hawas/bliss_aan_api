<?php

namespace App\Http\Resources\Api\V1\Client\Payments;

use App\Http\Resources\Concerns\FormatsApiValues;
use App\Models\SavedPaymentMethod;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

/**
 * A saved card (D188): brand, last four and expiry only. The token never leaves the API.
 *
 * @mixin SavedPaymentMethod
 */
class SavedPaymentMethodResource extends JsonResource
{
    use FormatsApiValues;

    public function toArray(Request $request): array
    {
        $m = $this->resource;

        return [
            'id' => $m->id,
            'brand' => $m->brand,
            'brand_label' => $m->brand === 'mada' ? 'mada' : Str::title($m->brand),
            'last4' => $m->last4,
            'exp_month' => $m->exp_month,
            'exp_year' => $m->exp_year,
            'is_expired' => $m->isExpired(),
            'is_default' => $m->is_default,
            'created_at' => $this->dateTime($m->created_at),
        ];
    }
}
