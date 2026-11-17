<?php

namespace App\Http\Resources\Api\V1\Admin\Cancellations;

use App\Http\Resources\Concerns\DescribesAdminCancellation;
use App\Http\Resources\Concerns\FormatsApiValues;
use App\Models\CancellationRequest;
use App\Services\Operations\PaymentSummary;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Dashboard cancellation request (D131) with a booking summary.
 *
 * @mixin CancellationRequest
 */
class AdminCancellationResource extends JsonResource
{
    use DescribesAdminCancellation, FormatsApiValues;

    public function toArray(Request $request): array
    {
        $c = $this->resource;
        $b = $c->booking;

        return [
            ...$this->adminCancellation($c),
            'booking' => $b ? [
                'id' => $b->id,
                'reference' => $b->reference,
                'status' => $b->status->value,
                'status_label' => $b->status->label(),
                'title' => $b->title,
                'total' => $this->money($b->total),
                'starts_at' => $this->dateTime($b->starts_at),
                'service_request_id' => $b->service_request_id,
                'service_request_reference' => $b->serviceRequest?->reference,
                'client' => ['id' => $b->client_id, 'name' => $b->client?->name],
                'provider' => ['id' => $b->provider_id, 'display_name' => $b->provider?->display_name],
            ] : null,
            // S17 (§5.3): the booking's Slice 10 payment summary and the refunds linked to this cancellation.
            'payment' => $b ? app(PaymentSummary::class)->forBooking($b) : null,
            'refunds' => app(PaymentSummary::class)->refundsForCancellation($c),
        ];
    }
}
