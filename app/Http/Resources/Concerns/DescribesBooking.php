<?php

namespace App\Http\Resources\Concerns;

use App\Models\Booking;

/**
 * Fields every audience shares for a booking (slice-5c-contract.md §4). Uses FormatsApiValues and DescribesOffer.
 * The time, title and total are snapshots (D107). `payment_status` is `not_required` in 5c (D113).
 */
trait DescribesBooking
{
    protected function bookingCore(Booking $b, bool $withOffer = true): array
    {
        $request = $b->serviceRequest;

        return [
            'id' => $b->id,
            'reference' => $b->reference,
            'status' => $b->status->value,
            'status_label' => $b->status->label(),
            'payment_status' => $b->payment_status->value,
            'payment_status_label' => $b->payment_status->label(),
            'title' => $b->title,
            'total' => $this->money($b->total),
            'service' => ['id' => $b->service_id, 'name' => $b->service?->name],
            'service_request' => $request ? [
                'id' => $request->id,
                'reference' => $request->reference,
                'type' => $request->type->value,
                'event_type' => ['id' => $request->event_type_id, 'name' => $request->eventType?->name],
                'event_date' => $request->event_date->format('Y-m-d'),
                'start_time' => $request->startTime(),
                'duration_minutes' => $request->duration_minutes,
                'city' => ['id' => $request->city_id, 'name' => $request->city?->name],
            ] : null,
            'execution_place' => $b->execution_place->value,
            'execution_place_label' => $b->execution_place->label(),
            'starts_at' => $this->dateTime($b->starts_at),
            'ends_at' => $this->dateTime($b->ends_at),
            ...($withOffer && $b->offer ? ['offer' => $this->offerCore($b->offer)] : []),
            'confirmed_at' => $this->dateTime($b->confirmed_at),
        ];
    }
}
