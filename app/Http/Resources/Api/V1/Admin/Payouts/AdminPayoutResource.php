<?php

namespace App\Http\Resources\Api\V1\Admin\Payouts;

use App\Http\Resources\Concerns\DescribesPayouts;
use App\Models\PayoutRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Payouts queue row (slice-11-contract.md §12).
 *
 * @mixin PayoutRequest
 */
class AdminPayoutResource extends JsonResource
{
    use DescribesPayouts;

    public function toArray(Request $request): array
    {
        $p = $this->resource;

        return [
            ...$this->payoutRequestBase($p),
            'provider' => ['id' => $p->provider_id, 'name' => $p->provider?->display_name],
            'created_at' => $this->dateTime($p->created_at),
            'processed_at' => $this->dateTime($p->processed_at),
        ];
    }
}
