<?php

namespace App\Http\Resources\Api\V1\Shared\Reviews;

use App\Http\Resources\Concerns\DescribesBookingExecution;
use App\Http\Resources\Concerns\FormatsApiValues;
use App\Models\Review;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public review: client first name only (D77), no booking data.
 *
 * @mixin Review
 */
class PublicReviewResource extends JsonResource
{
    use DescribesBookingExecution, FormatsApiValues;

    public function toArray(Request $request): array
    {
        return [
            ...$this->reviewItem($this->resource),
            'client' => ['first_name' => $this->firstName($this->resource->client)],
        ];
    }
}
