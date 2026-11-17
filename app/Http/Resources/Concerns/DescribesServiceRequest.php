<?php

namespace App\Http\Resources\Concerns;

use App\Enums\ActivityVisibility;
use App\Models\ActivityEvent;
use App\Models\ServiceRequest;
use App\Models\ServiceRequestAddon;

/**
 * Fields every audience shares for a service request (§6.3–§6.5). Uses FormatsApiValues.
 */
trait DescribesServiceRequest
{
    /**
     * Type, status, service, event, schedule (D48–D50, D62), city, guests and add-ons.
     */
    protected function requestCore(ServiceRequest $r): array
    {
        return [
            'id' => $r->id,
            'reference' => $r->reference,
            'type' => $r->type->value,
            'type_label' => $r->type->label(),
            'event_type' => ['id' => $r->event_type_id, 'name' => $r->eventType?->name],
            'execution_place' => $r->execution_place->value,
            'execution_place_label' => $r->execution_place->label(),
            'event_date' => $r->event_date->format('Y-m-d'),
            'start_time' => $r->startTime(),
            'duration_minutes' => $r->duration_minutes,
            'starts_at' => $this->dateTime($r->starts_at),
            'ends_at' => $this->dateTime($r->ends_at),
            'event_passed' => $r->eventPassed(),
            'city' => ['id' => $r->city_id, 'name' => $r->city?->name],
            'guests_count' => $r->guests_count,
            'addons' => $r->addons->map(fn (ServiceRequestAddon $a) => ['id' => $a->addon_id, 'name' => $a->name])->values(),
            // Slice 24 (D353): the package the request came from, for every audience; no price, nothing private.
            'package' => $r->package_id === null ? null : ['id' => $r->package_id, 'name' => $r->package?->name],
        ];
    }

    /**
     * History entries the viewer may see, oldest first.
     *
     * @param  list<ActivityVisibility>|null  $visibilities  null = every entry (admin)
     */
    protected function history(ServiceRequest $r, ?array $visibilities): array
    {
        return $r->history
            ->when($visibilities !== null, fn ($events) => $events->filter(fn (ActivityEvent $e) => in_array($e->visibility, $visibilities, true)))
            ->map(fn (ActivityEvent $e) => [
                'event' => $e->event,
                'event_label' => __('activity.'.$e->event),
                'from_status' => $e->from_status,
                'to_status' => $e->to_status,
                'at' => $this->dateTime($e->created_at),
            ])->values()->all();
    }
}
