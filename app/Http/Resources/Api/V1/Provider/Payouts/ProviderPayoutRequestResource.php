<?php

namespace App\Http\Resources\Api\V1\Provider\Payouts;

use App\Http\Resources\Concerns\DescribesPayouts;
use App\Models\PayoutRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Her own payout request: no internal note, no staff identity (slice-11-contract.md §12).
 *
 * @mixin PayoutRequest
 */
class ProviderPayoutRequestResource extends JsonResource
{
    use DescribesPayouts;

    public function toArray(Request $request): array
    {
        return $this->providerPayoutRequest($this->resource);
    }
}
