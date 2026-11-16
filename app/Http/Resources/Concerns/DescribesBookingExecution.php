<?php

namespace App\Http\Resources\Concerns;

use App\Enums\BookingStatus;
use App\Enums\CancellationStatus;
use App\Enums\ReviewTag;
use App\Models\Booking;
use App\Models\CancellationRequest;
use App\Models\Review;
use App\Models\User;
use App\Services\Bookings\BookingExecution;

/**
 * Slice 6 booking fields (slice-6-contract.md §6): execution times, cancellation requests, review and the
 * server-computed actions. Never the internal note or report details. Uses FormatsApiValues.
 * Needs `cancellationRequests` and `review` loaded.
 */
trait DescribesBookingExecution
{
    protected function bookingExecution(Booking $b, User $viewer, bool $asProvider): array
    {
        $pending = $b->pendingCancellation();
        $isConfirmed = $b->status === BookingStatus::Confirmed;
        $startAt = $asProvider ? app(BookingExecution::class)->startAvailableAt($b) : null;

        return [
            'started_at' => $this->dateTime($b->started_at),
            'completed_at' => $this->dateTime($b->completed_at),
            'cancelled_at' => $this->dateTime($b->cancelled_at),
            'cancellation_policy' => null, // no cancellation policy version exists yet (Q3, D113)
            'cancellation_requests' => $b->cancellationRequests->map(fn (CancellationRequest $c) => $this->cancellationItem($c, $viewer))->values()->all(),
            'review' => $b->review ? $this->reviewItem($b->review) : null,
            ...($asProvider ? ['start_available_at' => $this->dateTime($startAt)] : []),
            'actions' => [
                'can_start' => $asProvider && $isConfirmed && ! $pending && now()->gte($startAt),
                'can_complete' => $asProvider && $b->status === BookingStatus::InProgress,
                'can_request_cancellation' => $isConfirmed && ! $pending,
                'can_withdraw_cancellation' => $pending !== null && $pending->requester_id === $viewer->id,
                'can_review' => ! $asProvider && $b->status === BookingStatus::Completed && ! $b->review,
            ],
        ];
    }

    protected function cancellationItem(CancellationRequest $c, ?User $viewer = null): array
    {
        return [
            'id' => $c->id,
            'reference' => $c->reference,
            'requested_by' => $c->requester_role->value,
            'requested_by_label' => $c->requester_role->label(),
            'reason_code' => $c->reason_code->value,
            'reason_label' => $c->reason_code->label(),
            'reason_text' => $c->reason_text,
            'status' => $c->status->value,
            'status_label' => $c->status->label(),
            'decision_reason' => $c->decision_reason,
            'decided_at' => $this->dateTime($c->decided_at),
            'withdrawn_at' => $this->dateTime($c->withdrawn_at),
            'created_at' => $this->dateTime($c->created_at),
            ...($viewer ? ['is_mine' => $c->requester_id === $viewer->id] : []),
        ];
    }

    protected function reviewItem(Review $r): array
    {
        return [
            'id' => $r->id,
            'rating' => $r->rating,
            'comment' => $r->comment,
            'tags' => $this->reviewTags($r),
            'provider_reply' => $r->provider_reply,
            'replied_at' => $this->dateTime($r->replied_at),
            'created_at' => $this->dateTime($r->created_at),
        ];
    }

    /**
     * @return list<array{code: string, label: string}>
     */
    protected function reviewTags(Review $r): array
    {
        return collect($r->tags ?? [])
            ->map(fn (string $code) => ReviewTag::tryFrom($code))->filter()
            ->map(fn (ReviewTag $t) => ['code' => $t->value, 'label' => $t->label()])->values()->all();
    }

    protected function firstName(?User $user): ?string
    {
        $name = trim((string) $user?->name);

        return $name === '' ? null : preg_split('/\s+/u', $name)[0];
    }

    protected function isUnderReview(CancellationRequest $c): bool
    {
        return $c->status === CancellationStatus::UnderReview;
    }
}
