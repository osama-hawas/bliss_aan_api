<?php

namespace App\Http\Resources\Api\V1\Client\Store;

use App\Enums\StoreOrderStatus;
use App\Http\Resources\Concerns\DescribesStoreOrder;
use App\Http\Resources\Concerns\FormatsApiValues;
use App\Http\Resources\Concerns\PublicMediaUrl;
use App\Models\ActivityEvent;
use App\Models\StoreOrder;
use App\Models\StoreOrderItem;
use App\Models\StoreReturn;
use App\Services\Store\Returns\ReturnPolicy;
use App\Services\Store\Returns\StoreReturnRequests;
use App\Services\Store\StoreOrderLifecycle;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Her store order (`slice-9b-contract.md` §6.4). No staff identities, internal notes or staff cancel reasons.
 *
 * @mixin StoreOrder
 */
class ClientStoreOrderResource extends JsonResource
{
    use DescribesStoreOrder, FormatsApiValues, PublicMediaUrl;

    public function toArray(Request $request): array
    {
        /** @var StoreOrder $order */
        $order = $this->resource;
        $shipment = $order->latestShipment;
        $cancelReason = $order->status === StoreOrderStatus::Cancelled
            ? ($order->cancel_reason === StoreOrderLifecycle::CANCEL_STOCK ? 'stock_unavailable' : 'staff')
            : null;

        return [
            'id' => $order->id,
            'reference' => $order->reference,
            'status' => $order->status->value,
            'status_label' => $order->status->label(),
            'items' => $this->orderItems($order),
            'items_count' => (int) $order->items->sum('quantity'),
            ...$this->orderMoney($order),
            'address' => $this->orderAddress($order),
            'recipient' => ['name' => $order->recipient_name, 'phone' => $order->recipient_phone],
            // No confirming payment (unpaid, expired, or cancelled because stock ran out): show her latest attempt and its refund.
            'payment' => $this->orderPayment($order, latestAttempt: $order->payment_id === null),
            'shipment' => $shipment === null ? null : [
                'method' => $shipment->method->value,
                'method_label' => $shipment->method->label(),
                'status' => $shipment->status->value,
                'status_label' => $shipment->status->label(),
                'tracking_number' => $shipment->tracking_number,
                'tracking_url' => $shipment->tracking_url,
            ],
            'timeline' => $order->activity
                ->filter(fn (ActivityEvent $event) => $event->to_status !== null && self::enumValue($event->visibility) === 'all') // never internal entries
                ->map(fn (ActivityEvent $event) => [
                    'status' => $event->to_status,
                    'status_label' => StoreOrderStatus::tryFrom($event->to_status)?->label(),
                    'at' => $this->dateTime($event->created_at),
                ])->values(),
            'can_pay' => $order->status === StoreOrderStatus::PendingPayment,
            'can_reorder' => $order->status->isPlaced(),
            'cancel_reason' => $cancelReason,
            'cancel_reason_label' => $cancelReason ? __('store.cancel_reason.'.$cancelReason) : null,
            'created_at' => $this->dateTime($order->created_at),
            'confirmed_at' => $this->dateTime($order->confirmed_at),
            // S23 (store returns): her returns of this order and what she may still return.
            'returns' => $order->returns->map(fn (StoreReturn $return) => [
                'id' => $return->id,
                'reference' => $return->reference,
                'status' => $return->status->value,
                'status_label' => $return->status->label(),
            ])->values(),
            'return_options' => $this->returnOptions($order),
        ];
    }

    /**
     * S23 (D343): only on a delivered order. `can_request` = inside the window with something left to return.
     *
     * @return array<string, mixed>|null
     */
    private function returnOptions(StoreOrder $order): ?array
    {
        if ($order->status !== StoreOrderStatus::Delivered) {
            return null;
        }
        $policy = app(ReturnPolicy::class);
        $days = $policy->windowDays();
        $endsAt = $policy->windowEndsAt($order, $days);
        $returnable = StoreReturnRequests::returnable($order);
        $open = $endsAt !== null && now()->lessThanOrEqualTo($endsAt) && $order->payment_id !== null;

        return [
            'window_days' => $days,
            'window_ends_at' => $this->dateTime($endsAt),
            'can_request' => $open && array_sum($returnable) > 0,
            'items' => $order->items->map(fn (StoreOrderItem $item) => [
                'order_item_id' => $item->id,
                'variant_id' => $item->product_variant_id,
                'name' => $item->localizedName(),
                'quantity' => $item->quantity,
                'returnable_quantity' => $returnable[$item->id] ?? 0,
            ])->values(),
        ];
    }
}
