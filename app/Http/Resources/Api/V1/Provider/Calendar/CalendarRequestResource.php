<?php

namespace App\Http\Resources\Api\V1\Provider\Calendar;

use App\Http\Resources\Concerns\FormatsApiValues;
use App\Models\ServiceRequestRecipient;
use App\Services\Availability\ProviderAvailability;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A request on her calendar (§5.2): a subset of ProviderRequestResource with the same keys and privacy (D56), plus
 * `calendar_conflict`.
 *
 * @mixin ServiceRequestRecipient
 */
class CalendarRequestResource extends JsonResource
{
    use FormatsApiValues;

    public function toArray(Request $request): array
    {
        $r = $this->serviceRequest;

        return [
            'id' => $r->id,
            'reference' => $r->reference,
            'type' => $r->type->value,
            'type_label' => $r->type->label(),
            'request_status' => $r->status->value,
            'request_status_label' => $r->status->label(),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'service' => ['id' => $r->service_id, 'name' => $r->service?->name],
            'event_date' => $r->event_date->format('Y-m-d'),
            'start_time' => $r->startTime(),
            'duration_minutes' => $r->duration_minutes,
            'starts_at' => $this->dateTime($r->starts_at),
            'ends_at' => $this->dateTime($r->ends_at),
            'event_passed' => $r->eventPassed(),
            'city' => ['id' => $r->city_id, 'name' => $r->city?->name],
            'calendar_conflict' => app(ProviderAvailability::class)->hasConflict($this->resource),
        ];
    }
}
