<?php

namespace App\Http\Resources\Api\V1\Admin\Store;

use App\Http\Resources\Concerns\FormatsApiValues;
use App\Models\Shipment;
use App\Models\StoreOrderItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An internal delivery assigned to the calling officer (`delivery.assigned`): what she needs to deliver, nothing else.
 *
 * @mixin Shipment
 */
class DeliveryResource extends JsonResource
{
    use FormatsApiValues;

    public function toArray(Request $request): array
    {
        $order = $this->order;
        $address = $order->address ?? [];
        $city = $address['city'] ?? null;

        return [
            'id' => $this->id,
            'order_id' => $order->id,
            'reference' => $order->reference,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'recipient' => ['name' => $order->recipient_name, 'phone' => $order->recipient_phone],
            'address' => [
                'city' => is_array($city) ? ($city[app()->getLocale()] ?? $city['ar'] ?? null) : $city,
                'district' => $address['district'] ?? null,
                'details' => $address['details'] ?? null,
                'lat' => $address['lat'] ?? null,
                'lng' => $address['lng'] ?? null,
            ],
            'items' => $order->items->map(fn (StoreOrderItem $item) => [
                'name' => $item->localizedName(),
                'options' => $item->resolvedOptions(),
                'quantity' => $item->quantity,
            ])->values(),
            'created_at' => $this->dateTime($this->created_at),
            'updated_at' => $this->dateTime($this->updated_at),
        ];
    }
}
