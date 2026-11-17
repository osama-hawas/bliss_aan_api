<?php

namespace App\Http\Resources\Api\V1\Admin\Reports;

use App\Http\Resources\Concerns\FormatsApiValues;
use App\Models\Booking;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A next confirmed booking on the overview (D328), as the dashboard mock shaped it.
 *
 * @mixin Booking
 */
class UpcomingBookingResource extends JsonResource
{
    use FormatsApiValues;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'starts_at' => $this->dateTime($this->starts_at),
            'service' => ['name' => $this->service?->getTranslations('name') ?: null],
            'client' => ['name' => $this->client?->name],
            'provider' => ['name' => $this->provider?->display_name],
        ];
    }
}
