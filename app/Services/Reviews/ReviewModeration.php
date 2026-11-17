<?php

namespace App\Services\Reviews;

use App\Enums\ActivityVisibility;
use App\Enums\ContentReportDecision;
use App\Enums\ContentReportStatus;
use App\Enums\ErrorCode;
use App\Enums\ReviewStatus;
use App\Exceptions\ApiException;
use App\Models\ContentReport;
use App\Models\Provider;
use App\Models\Review;
use App\Models\User;
use App\Services\Audit\ActivityRecorder;
use App\Services\Audit\AuditLogger;
use App\Services\Availability\DeadlockRetry;
use Illuminate\Support\Facades\DB;

/**
 * Review moderation (`reviews.moderate`, slice-17-contract.md §8, D261, D262). Lock order = the ReviewService one:
 * provider row → review → its reports. The rating cache is recomputed from published reviews in the same transaction.
 * Nobody is notified.
 */
class ReviewModeration
{
    public function __construct(
        private readonly ReviewService $reviews,
        private readonly ActivityRecorder $activity,
        private readonly AuditLogger $audit,
        private readonly DeadlockRetry $retry,
    ) {}

    public function hide(User $staff, Review $review, string $reason, ?string $note): Review
    {
        return $this->retry->run(fn () => DB::transaction(function () use ($staff, $review, $reason, $note) {
            [$provider, $review] = $this->lock($review);
            if ($review->status !== ReviewStatus::Published) {
                throw new ApiException(ErrorCode::InvalidState);
            }

            $this->applyHide($staff, $provider, $review, $reason, $note);
            $this->closeReports($staff, $review, ContentReportDecision::Actioned, $reason, null, null);

            return $review;
        }), 'review.hide', ['review_id' => $review->id]);
    }

    public function restore(User $staff, Review $review, string $reason): Review
    {
        return $this->retry->run(fn () => DB::transaction(function () use ($staff, $review, $reason) {
            [$provider, $review] = $this->lock($review);
            if ($review->status !== ReviewStatus::Hidden) {
                throw new ApiException(ErrorCode::InvalidState);
            }

            $old = ['status' => $review->status->value, 'hidden_reason' => $review->hidden_reason, 'hidden_by' => $review->hidden_by,
                'hidden_at' => $review->hidden_at?->toIso8601ZuluString(), 'moderation_note' => $review->moderation_note];
            $review->update(['status' => ReviewStatus::Published, 'hidden_reason' => null, 'hidden_by' => null, 'hidden_at' => null, 'moderation_note' => null]);
            $this->reviews->refreshRating($provider);
            $this->history($staff, $review, 'review_restored');
            $this->audit->log('review.restored', $staff, $review, $reason, $old, ['status' => ReviewStatus::Published->value]);

            return $review;
        }), 'review.restore', ['review_id' => $review->id]);
    }

    /**
     * `actioned` hides a published review (this report's reason) and closes its other open reports as actioned;
     * `dismissed` closes this report only (D262).
     */
    public function decide(User $staff, ContentReport $report, ContentReportDecision $decision, string $reason, ?string $note): ContentReport
    {
        $review = $report->reportable;
        if (! $review instanceof Review) {
            throw new ApiException(ErrorCode::NotFound); // only reviews are reportable today
        }

        return $this->retry->run(fn () => DB::transaction(function () use ($staff, $report, $review, $decision, $reason, $note) {
            [$provider, $review] = $this->lock($review);
            $report = ContentReport::query()->whereKey($report->id)->lockForUpdate()->firstOrFail();
            if ($report->status !== ContentReportStatus::Open) {
                throw new ApiException(ErrorCode::InvalidState);
            }

            if ($decision === ContentReportDecision::Actioned) {
                if ($review->status === ReviewStatus::Published) {
                    $this->applyHide($staff, $provider, $review, $reason, $note);
                }
                $this->closeReports($staff, $review, ContentReportDecision::Actioned, $reason, $note, $report->id);
            } else {
                $this->closeOne($staff, $report, $decision, $reason, $note);
            }
            $this->audit->log('content_report.decided', $staff, $report, $reason, ['status' => ContentReportStatus::Open->value],
                ['status' => ContentReportStatus::Handled->value, 'decision' => $decision->value, 'review_status' => $review->status->value]);

            return $report->refresh();
        }), 'content_report.decide', ['content_report_id' => $report->id]);
    }

    private function applyHide(User $staff, Provider $provider, Review $review, string $reason, ?string $note): void
    {
        $review->update(['status' => ReviewStatus::Hidden, 'hidden_reason' => $reason, 'hidden_by' => $staff->id, 'hidden_at' => now(), 'moderation_note' => $note]);
        $this->reviews->refreshRating($provider);
        $this->history($staff, $review, 'review_hidden');
        $this->audit->log('review.hidden', $staff, $review, $reason, ['status' => ReviewStatus::Published->value],
            ['status' => ReviewStatus::Hidden->value, 'moderation_note' => $note]);
    }

    /**
     * Closes every open report on the review with the decision. `$firstId` keeps the decided report's own note.
     */
    private function closeReports(User $staff, Review $review, ContentReportDecision $decision, string $reason, ?string $note, ?int $firstId): void
    {
        ContentReport::query()->where('reportable_type', $review->getMorphClass())->where('reportable_id', $review->id)
            ->where('status', ContentReportStatus::Open)->orderBy('id')->lockForUpdate()->get()
            ->each(fn (ContentReport $r) => $this->closeOne($staff, $r, $decision, $reason, $r->id === $firstId ? $note : null));
    }

    private function closeOne(User $staff, ContentReport $report, ContentReportDecision $decision, string $reason, ?string $note): void
    {
        $report->update([
            'status' => ContentReportStatus::Handled, 'decision' => $decision, 'decision_reason' => $reason,
            'internal_note' => $note, 'handled_by' => $staff->id, 'handled_at' => now(),
        ]);
    }

    /**
     * @return array{0: Provider, 1: Review}
     */
    private function lock(Review $review): array
    {
        $provider = Provider::query()->whereKey($review->provider_id)->lockForUpdate()->firstOrFail();
        $review = Review::query()->whereKey($review->id)->lockForUpdate()->firstOrFail();

        return [$provider, $review];
    }

    private function history(User $staff, Review $review, string $event): void
    {
        $request = $review->booking?->serviceRequest;
        if ($request) {
            $this->activity->record($request, $event, null, null, $staff, ActivityVisibility::Internal, ['review_id' => $review->id]);
        }
    }
}
