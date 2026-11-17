<?php

namespace App\Services\Reviews;

use App\Models\Provider;
use App\Models\Review;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Review lookups (slice-6-contract.md §5). Public lists show published reviews of visible providers only (D75).
 */
class ReviewQueries
{
    public function forProvider(Provider $provider, int $perPage): LengthAwarePaginator
    {
        return Review::query()->published()->where('provider_id', $provider->id) // S17 (D261): hidden reviews leave her list
            ->with(['client', 'booking'])
            ->withExists(['reports as reported_by_me' => fn ($q) => $q->where('reporter_id', $provider->user_id)])
            ->orderByDesc('created_at')->orderByDesc('id')
            ->paginate($perPage);
    }

    public function providerReview(Provider $provider, int $id): Review
    {
        // S17 (D261): a hidden review is out of her list, so she can neither reply to it nor report it (404).
        return Review::query()->published()->where('provider_id', $provider->id)->findOrFail($id);
    }

    /**
     * @return array{average: float|null, count: int, breakdown: array<string, int>}
     */
    public function summary(Provider $provider): array
    {
        $counts = Review::query()->published()->where('provider_id', $provider->id)
            ->selectRaw('rating, COUNT(*) as c')->groupBy('rating')->pluck('c', 'rating');
        $count = (int) $counts->sum();
        $breakdown = [];
        foreach ([5, 4, 3, 2, 1] as $stars) {
            $breakdown[(string) $stars] = (int) ($counts[$stars] ?? 0);
        }
        $sum = 0;
        foreach ($counts as $stars => $c) {
            $sum += (int) $stars * (int) $c;
        }

        return ['average' => $count ? round($sum / $count, 2) : null, 'count' => $count, 'breakdown' => $breakdown];
    }

    public function publicForProvider(int $providerId, int $perPage): LengthAwarePaginator
    {
        $provider = Provider::query()->visibleToClients()->findOrFail($providerId);

        return Review::query()->published()->where('provider_id', $provider->id)
            ->with('client')
            ->orderByDesc('created_at')->orderByDesc('id')
            ->paginate($perPage);
    }
}
