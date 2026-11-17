<?php

namespace App\Http\Resources\Api\V1\Provider\Payouts;

use App\Http\Resources\Concerns\DescribesPayouts;
use App\Models\PayoutAccount;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Her payout account, IBAN masked (D216, D223).
 *
 * @mixin PayoutAccount
 */
class PayoutAccountResource extends JsonResource
{
    use DescribesPayouts;

    public function toArray(Request $request): array
    {
        return $this->payoutAccount($this->resource);
    }
}
