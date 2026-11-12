<?php

namespace App\Http\Resources\Api\V1\Client\Addresses;

use App\Http\Resources\Concerns\FormatsApiValues;
use App\Models\Address;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Address
 */
class AddressResource extends JsonResource
{
    use FormatsApiValues;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'city' => ['id' => $this->city_id, 'name' => $this->city?->name],
            'district' => $this->district,
            'details' => $this->details,
            'lat' => $this->lat,
            'lng' => $this->lng,
            'recipient_name' => $this->recipient_name,
            'recipient_phone' => $this->recipient_phone,
            'is_default' => $this->is_default,
            'created_at' => $this->dateTime($this->created_at),
        ];
    }
}
