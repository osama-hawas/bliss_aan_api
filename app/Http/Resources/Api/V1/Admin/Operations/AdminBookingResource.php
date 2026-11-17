<?php

namespace App\Http\Resources\Api\V1\Admin\Operations;

use App\Enums\BookingStatus;
use App\Enums\Permission;
use App\Http\Resources\Concerns\DescribesAdminCancellation;
use App\Http\Resources\Concerns\DescribesAdminReview;
use App\Http\Resources\Concerns\DescribesOffer;
use App\Http\Resources\Concerns\DescribesStaffHistory;
use App\Http\Resources\Concerns\FormatsApiValues;
use App\Models\Booking;
use App\Models\CancellationRequest;
use App\Models\SupportTicket;
use App\Services\Operations\BookingAdminQueries;
use App\Services\Operations\PaymentSummary;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Dashboard booking (`bookings.view`, slice-17-contract.md §5.1). The list row is short; `detailed()` adds the complete
 * booking (address with pin, D64), cancellation requests with their refunds, the review with moderation fields, the
 * Slice 10 payment summary, linked tickets, the request's full history and the staff actions.
 *
 * @mixin Booking
 */
class AdminBookingResource extends JsonResource
{
    use DescribesAdminCancellation, DescribesAdminReview, DescribesOffer, DescribesStaffHistory, FormatsApiValues;

    private bool $full = false;

    public function detailed(): static
    {
        $this->full = true;

        return $this;
    }

    public function toArray(Request $request): array
    {
        $b = $this->resource;
        $sr = $b->serviceRequest;

        $row = [
            'id' => $b->id,
            'reference' => $b->reference,
            'status' => $b->status->value,
            'status_label' => $b->status->label(),
            'payment_status' => $b->payment_status->value,
            'payment_status_label' => $b->payment_status->label(),
            'title' => $b->title,
            'total' => $this->money($b->total),
            'service' => ['id' => $b->service_id, 'name' => $b->service?->name],
            'client' => ['id' => $b->client_id, 'name' => $b->client?->name, 'phone' => $b->client?->phone],
            'provider' => ['id' => $b->provider_id, 'display_name' => $b->provider?->display_name],
            'city' => $sr ? ['id' => $sr->city_id, 'name' => $sr->city?->name] : null,
            'starts_at' => $this->dateTime($b->starts_at),
            'ends_at' => $this->dateTime($b->ends_at),
            'request' => $sr ? ['id' => $sr->id, 'reference' => $sr->reference] : null,
            'has_pending_cancellation' => (bool) ($b->has_pending_cancellation ?? $b->pendingCancellation()),
            'open_tickets_count' => (int) ($b->open_tickets_count ?? 0),
            'created_at' => $this->dateTime($b->created_at),
        ];

        if (! $this->full) {
            return $row;
        }

        $staff = $request->user();
        $canIntervene = $staff->checkPermissionTo(Permission::BookingsIntervene->value);
        $payments = app(PaymentSummary::class);
        $payment = $payments->forBooking($b);
        $pending = $b->cancellationRequests->contains(fn (CancellationRequest $c) => $this->isUnderReview($c));
        $events = $sr?->history ?? collect();

        return [
            ...$row,
            'execution_place' => $b->execution_place->value,
            'execution_place_label' => $b->execution_place->label(),
            'event_type' => $sr ? ['id' => $sr->event_type_id, 'name' => $sr->eventType?->name] : null,
            'address' => $b->address,
            'offer' => $b->offer ? $this->offerCore($b->offer) : null,
            'policy_version_id' => $b->policy_version_id,
            'confirmed_at' => $this->dateTime($b->confirmed_at),
            'started_at' => $this->dateTime($b->started_at),
            'started_by' => $b->started_by ? ['id' => $b->started_by, 'name' => $b->startedBy?->name] : null,
            'completed_at' => $this->dateTime($b->completed_at),
            'completed_by' => $b->completed_by ? ['id' => $b->completed_by, 'name' => $b->completedBy?->name] : null,
            'cancelled_at' => $this->dateTime($b->cancelled_at),
            'cancellation_requests' => $b->cancellationRequests->map(fn (CancellationRequest $c) => [
                ...$this->adminCancellation($c),
                'refunds' => $payments->refundsForCancellation($c),
            ])->values()->all(),
            'review' => $b->review ? $this->adminReview($b->review) : null,
            'payment' => $payment,
            'tickets' => app(BookingAdminQueries::class)->tickets($b)->map(fn (SupportTicket $t) => [
                'id' => $t->id, 'reference' => $t->reference, 'status' => $t->status->value, 'status_label' => $t->status->label(),
                'subject' => $t->subject, 'created_at' => $this->dateTime($t->created_at),
            ])->values()->all(),
            'history' => $this->staffHistory($events),
            'staff_notes' => $this->staffNotes($events),
            'actions' => [
                'can_cancel' => $canIntervene && in_array($b->status, [BookingStatus::Confirmed, BookingStatus::InProgress], true) && ! $pending,
                'can_complete' => $canIntervene && $b->status === BookingStatus::InProgress,
                'can_add_note' => $canIntervene,
                'can_refund' => $payment !== null && $payment['can_refund'] && $staff->checkPermissionTo(Permission::RefundsProcess->value),
            ],
        ];
    }
}
