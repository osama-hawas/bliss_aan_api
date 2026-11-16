<?php

namespace App\Http\Resources\Api\V1\Provider\Reviews;

use App\Http\Resources\Concerns\DescribesBookingExecution;
use App\Http\Resources\Concerns\FormatsApiValues;
use App\Models\Review;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A review of her work: client first name only (D77), whether she reported it.
 *
 * @mixin Review
 */
class ProviderReviewResource extends JsonResource
{
    use DescribesBookingExecution, FormatsApiValues;

    public function toArray(Request $request): array
    {
        $r = $this->resource;

        return [
            ...$this->reviewItem($r),
            'booking' => ['id' => $r->booking_id, 'reference' => $r->booking?->reference],
            'client' => ['first_name' => $this->firstName($r->client)],
            'reported' => (bool) ($r->reported_by_me ?? $r->reports()->where('reporter_id', $request->user()->id)->exists()),
        ];
    }
}
