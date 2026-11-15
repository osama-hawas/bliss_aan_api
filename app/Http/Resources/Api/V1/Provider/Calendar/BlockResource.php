<?php

namespace App\Http\Resources\Api\V1\Provider\Calendar;

use App\Http\Resources\Concerns\FormatsApiValues;
use App\Models\AvailabilityBlock;
use App\Support\RiyadhCalendar;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One of her active blocks (§5.1). Deleted blocks are never serialized.
 *
 * @mixin AvailabilityBlock
 */
class BlockResource extends JsonResource
{
    use FormatsApiValues;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'starts_at' => $this->dateTime($this->starts_at),
            'ends_at' => $this->dateTime($this->ends_at),
            'from_date' => RiyadhCalendar::dateOf($this->starts_at),
            'to_date' => RiyadhCalendar::lastDateBefore($this->ends_at),
            'whole_days' => $this->wholeDays(),
            'reason' => $this->reason === '' ? null : $this->reason,
            'created_at' => $this->dateTime($this->created_at),
        ];
    }
}
