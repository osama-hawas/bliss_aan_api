<?php

namespace App\Http\Resources\Api\V1\Admin\Store;

use App\Http\Resources\Concerns\FormatsApiValues;
use App\Models\StoreOrder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A store order row in the staff list (`slice-9b-contract.md` §8.2).
 *
 * @mixin StoreOrder
 */
class AdminStoreOrderRowResource extends JsonResource
{
    use FormatsApiValues;

    public function toArray(Request $request): array
    {
        /** @var StoreOrder $order */
        $order = $this->resource;
        $shipment = $order->latestShipment;
        $city = $order->address['city'] ?? null;

        return [
            'id' => $order->id,
            'reference' => $order->reference,
            'status' => $order->status->value,
            'status_label' => $order->status->label(),
            'client' => ['id' => $order->user?->id, 'name' => $order->user?->name, 'phone' => $order->user?->phone],
            'city' => is_array($city) ? ($city[app()->getLocale()] ?? $city['ar'] ?? null) : $city,
            'items_count' => (int) ($order->items_count ?? $order->items()->sum('quantity')),
            'total' => $this->money($order->total),
            'shipment' => $shipment === null ? null : [
                'method' => $shipment->method->value,
                'method_label' => $shipment->method->label(),
                'status' => $shipment->status->value,
                'status_label' => $shipment->status->label(),
                'assigned_to' => $shipment->assignee ? ['id' => $shipment->assignee->id, 'name' => $shipment->assignee->name] : null,
            ],
            'created_at' => $this->dateTime($order->created_at),
        ];
    }
}
