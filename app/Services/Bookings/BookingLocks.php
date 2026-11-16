<?php

namespace App\Services\Bookings;

use App\Models\Booking;
use App\Models\CancellationRequest;
use App\Models\ServiceRequest;
use Illuminate\Support\Collection;

/**
 * Slice 6 lock order (slice-6-contract.md §3–§4), the same direction as 5c (request before booking):
 * request row → booking row → the booking's cancellation requests. Call inside a transaction.
 */
class BookingLocks
{
    /**
     * @return array{0: ServiceRequest, 1: Booking, 2: Collection<int, CancellationRequest>}
     */
    public function lock(Booking $booking): array
    {
        $request = ServiceRequest::query()->whereKey($booking->service_request_id)->lockForUpdate()->firstOrFail();
        $locked = Booking::query()->whereKey($booking->id)->lockForUpdate()->firstOrFail();
        $cancellations = CancellationRequest::query()->where('booking_id', $booking->id)->orderBy('id')->lockForUpdate()->get();

        return [$request, $locked, $cancellations];
    }
}
