<?php

namespace App\Services\Bookings;

use App\Models\Booking;
use App\Models\Provider;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Booking lookups, always scoped to the owner first, so another id is a 404.
 */
class BookingQueries
{
    public const SORTS = ['starts_at', '-starts_at', 'confirmed_at', '-confirmed_at'];

    public const CLIENT_WITH = ['provider.avatar', 'service', 'offer.items', 'serviceRequest.eventType', 'serviceRequest.city', 'cancellationRequests', 'review'];

    public const PROVIDER_WITH = ['client', 'service', 'offer.items', 'serviceRequest.eventType', 'serviceRequest.city', 'cancellationRequests', 'review',
        'team.photo', // Slice 13 (S13), provider only
    ];

    /**
     * @param  list<string>  $statuses
     */
    public function forClient(User $client, array $statuses, ?string $sort, int $perPage): LengthAwarePaginator
    {
        return $this->list(Booking::query()->where('client_id', $client->id)->with(self::CLIENT_WITH), $statuses, $sort, $perPage);
    }

    public function clientBooking(User $client, int $id): Booking
    {
        return Booking::query()->where('client_id', $client->id)->with(self::CLIENT_WITH)->findOrFail($id);
    }

    /**
     * @param  list<string>  $statuses
     */
    public function forProvider(Provider $provider, array $statuses, ?string $sort, int $perPage): LengthAwarePaginator
    {
        return $this->list(Booking::query()->where('provider_id', $provider->id)->with(self::PROVIDER_WITH), $statuses, $sort, $perPage);
    }

    public function providerBooking(Provider $provider, int $id): Booking
    {
        return Booking::query()->where('provider_id', $provider->id)->with(self::PROVIDER_WITH)->findOrFail($id);
    }

    private function list(Builder $query, array $statuses, ?string $sort, int $perPage): LengthAwarePaginator
    {
        $sort ??= 'starts_at';

        return $query
            ->when($statuses, fn (Builder $q, array $s) => $q->whereIn('status', $s))
            ->orderBy(ltrim($sort, '-'), str_starts_with($sort, '-') ? 'desc' : 'asc')
            ->orderByDesc('id')
            ->paginate($perPage);
    }
}
