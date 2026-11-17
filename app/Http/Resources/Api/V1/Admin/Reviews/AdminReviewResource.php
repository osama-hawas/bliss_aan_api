<?php

namespace App\Http\Resources\Api\V1\Admin\Reviews;

use App\Http\Resources\Concerns\DescribesAdminReview;
use App\Http\Resources\Concerns\DescribesBookingExecution;
use App\Http\Resources\Concerns\FormatsApiValues;
use App\Models\Review;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Dashboard review (`reviews.moderate`, slice-17-contract.md §8): full client name (staff), provider, booking, the
 * moderation trail and the number of open reports.
 *
 * @mixin Review
 */
class AdminReviewResource extends JsonResource
{
    use DescribesAdminReview, DescribesBookingExecution, FormatsApiValues;

    public function toArray(Request $request): array
    {
        $r = $this->resource;

        return [
            ...$this->adminReview($r),
            'client' => ['id' => $r->client_id, 'name' => $r->client?->name],
            'provider' => ['id' => $r->provider_id, 'display_name' => $r->provider?->display_name],
            'service' => ['id' => $r->booking?->service_id, 'name' => $r->booking?->service?->name],
            'booking' => ['id' => $r->booking_id, 'reference' => $r->booking?->reference],
            'open_reports_count' => (int) ($r->open_reports_count ?? 0),
        ];
    }
}
