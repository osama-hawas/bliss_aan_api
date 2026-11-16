<?php

namespace App\Services\Reviews;

use App\Enums\ActivityVisibility;
use App\Enums\BookingStatus;
use App\Enums\ContentReportStatus;
use App\Enums\ErrorCode;
use App\Enums\ReportReason;
use App\Enums\ReviewStatus;
use App\Exceptions\ApiException;
use App\Models\Booking;
use App\Models\ContentReport;
use App\Models\Provider;
use App\Models\Review;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Services\Audit\ActivityRecorder;
use App\Services\Availability\DeadlockRetry;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Reviews (Req 25, D129; slice-6-contract.md §5): one per completed booking, published at once, never edited or
 * deleted; one provider reply; reports stored for Slice 17. The provider's rating cache is recomputed under her row
 * lock in the same transaction.
 */
class ReviewService
{
    public function __construct(
        private readonly ActivityRecorder $activity,
        private readonly DeadlockRetry $retry,
    ) {}

    /**
     * @param  list<string>  $tags
     */
    public function submit(User $client, Booking $booking, int $rating, ?string $comment, array $tags): Review
    {
        return $this->retry->run(fn () => DB::transaction(function () use ($client, $booking, $rating, $comment, $tags) {
            $request = ServiceRequest::query()->whereKey($booking->service_request_id)->lockForUpdate()->firstOrFail();
            $booking = Booking::query()->whereKey($booking->id)->where('client_id', $client->id)->lockForUpdate()->firstOrFail();

            if ($booking->status !== BookingStatus::Completed) {
                throw new ApiException(ErrorCode::InvalidState);
            }
            if (Review::query()->where('booking_id', $booking->id)->exists()) {
                throw new ApiException(ErrorCode::AlreadyReviewed);
            }
            $provider = Provider::query()->whereKey($booking->provider_id)->lockForUpdate()->firstOrFail();

            try {
                $review = Review::query()->create([
                    'booking_id' => $booking->id,
                    'client_id' => $client->id,
                    'provider_id' => $provider->id,
                    'rating' => $rating,
                    'comment' => $comment,
                    'tags' => array_values(array_unique($tags)),
                    'status' => ReviewStatus::Published,
                ]);
            } catch (UniqueConstraintViolationException) {
                throw new ApiException(ErrorCode::AlreadyReviewed);
            }

            $this->refreshRating($provider);
            $this->activity->record($request, 'review_published', null, null, $client, ActivityVisibility::All, ['booking_id' => $booking->id, 'review_id' => $review->id]);

            return $review;
        }), 'review.submit', ['booking_id' => $booking->id]);
    }

    public function reply(Provider $provider, Review $review, string $reply): Review
    {
        return DB::transaction(function () use ($provider, $review, $reply) {
            $review = Review::query()->whereKey($review->id)->where('provider_id', $provider->id)->lockForUpdate()->firstOrFail();
            if ($review->provider_reply !== null) {
                throw new ApiException(ErrorCode::InvalidState); // one reply, never edited
            }
            $review->update(['provider_reply' => $reply, 'replied_at' => now()]);

            return $review;
        });
    }

    public function report(User $reporter, Review $review, ReportReason $reason, ?string $details): ContentReport
    {
        $exists = fn () => ContentReport::query()->where('reportable_type', $review->getMorphClass())
            ->where('reportable_id', $review->id)->where('reporter_id', $reporter->id)->exists();
        if ($exists()) {
            throw new ApiException(ErrorCode::AlreadyReported);
        }

        try {
            return ContentReport::query()->create([
                'reportable_type' => $review->getMorphClass(),
                'reportable_id' => $review->id,
                'reporter_id' => $reporter->id,
                'reason_code' => $reason,
                'details' => $details,
                'status' => ContentReportStatus::Open,
            ]);
        } catch (UniqueConstraintViolationException) {
            throw new ApiException(ErrorCode::AlreadyReported);
        }
    }

    /**
     * Recomputes the cached rating from her published reviews. Call with her row locked.
     */
    public function refreshRating(Provider $provider): void
    {
        $stats = Review::query()->published()->where('provider_id', $provider->id)
            ->selectRaw('COUNT(*) as c, AVG(rating) as a')->first();
        $count = (int) $stats->c;
        $provider->update([
            'rating_count' => $count,
            'rating_avg' => $count > 0 ? round((float) $stats->a, 2) : null,
        ]);
    }
}
