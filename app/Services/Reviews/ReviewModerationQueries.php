<?php

namespace App\Services\Reviews;

use App\Enums\ContentReportStatus;
use App\Models\ContentReport;
use App\Models\Review;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Dashboard review and report lists (`reviews.moderate`, slice-17-contract.md §8).
 */
class ReviewModerationQueries
{
    public const REVIEW_SORTS = ['created_at', '-created_at', 'rating', '-rating'];

    /**
     * @param  array<string, mixed>  $filters
     */
    public function reviews(array $filters, ?string $sort, int $perPage): LengthAwarePaginator
    {
        $sort ??= '-created_at';

        return Review::query()
            ->with(['client', 'provider', 'booking.service', 'hiddenBy'])
            ->withCount(['reports as open_reports_count' => fn (Builder $q) => $q->where('status', ContentReportStatus::Open)])
            ->when($filters['q'] ?? null, fn (Builder $q, string $text) => $q->where(fn (Builder $w) => $w
                ->whereHas('booking', fn (Builder $b) => $b->where('reference', 'like', "%{$text}%"))
                ->orWhereHas('client', fn (Builder $c) => $c->where('name', 'like', "%{$text}%"))
                ->orWhereHas('provider', fn (Builder $p) => $p->where('display_name', 'like', "%{$text}%"))))
            ->when($filters['status'] ?? null, fn (Builder $q, string $s) => $q->where('status', $s))
            ->when($filters['rating'] ?? null, fn (Builder $q, int $r) => $q->where('rating', $r))
            ->when($filters['provider_id'] ?? null, fn (Builder $q, int $id) => $q->where('provider_id', $id))
            ->when(($filters['reported'] ?? null) !== null, fn (Builder $q) => $filters['reported']
                ? $q->whereHas('reports', fn (Builder $r) => $r->where('status', ContentReportStatus::Open))
                : $q->whereDoesntHave('reports', fn (Builder $r) => $r->where('status', ContentReportStatus::Open)))
            ->orderBy(ltrim($sort, '-'), str_starts_with($sort, '-') ? 'desc' : 'asc')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    public function review(int $id): Review
    {
        return Review::query()->with(['client', 'provider', 'booking.service', 'hiddenBy'])
            ->withCount(['reports as open_reports_count' => fn (Builder $q) => $q->where('status', ContentReportStatus::Open)])
            ->findOrFail($id);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function reports(array $filters, int $perPage): LengthAwarePaginator
    {
        return ContentReport::query()
            ->with(['reporter', 'handler', 'reportable'])
            ->when($filters['status'] ?? null, fn (Builder $q, string $s) => $q->where('status', $s))
            ->when($filters['reason'] ?? null, fn (Builder $q, string $r) => $q->where('reason_code', $r))
            ->when($filters['target_type'] ?? null, fn (Builder $q) => $q->where('reportable_type', (new Review)->getMorphClass()))
            ->orderByRaw("CASE WHEN status = 'open' THEN 0 ELSE 1 END")
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    public function report(int $id): ContentReport
    {
        return ContentReport::query()->with(['reporter', 'handler', 'reportable'])->findOrFail($id);
    }
}
