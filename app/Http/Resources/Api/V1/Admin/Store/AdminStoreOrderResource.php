<?php

namespace App\Http\Resources\Api\V1\Admin\Store;

use App\Enums\ShipmentMethod;
use App\Enums\ShipmentStatus;
use App\Enums\StoreOrderStatus;
use App\Http\Resources\Concerns\DescribesStoreOrder;
use App\Http\Resources\Concerns\FormatsApiValues;
use App\Http\Resources\Concerns\PublicMediaUrl;
use App\Models\ActivityEvent;
use App\Models\Shipment;
use App\Models\ShipmentEvent;
use App\Models\StoreOrder;
use Illuminate\Http\Request;

/**
 * A store order for staff: everything the client sees plus client contact, shipments with events, the payment link,
 * the full timeline (internal notes and actors) and the actions available now (`slice-9b-contract.md` §8.2).
 *
 * @mixin StoreOrder
 */
class AdminStoreOrderResource extends AdminStoreOrderRowResource
{
    use DescribesStoreOrder, FormatsApiValues, PublicMediaUrl;

    public function toArray(Request $request): array
    {
        /** @var StoreOrder $order */
        $order = $this->resource->loadMissing(['items.variant.product.image', 'shipments.assignee', 'shipments.events.actor', 'activity.actor', 'user']);

        return [
            ...parent::toArray($request),
            'items' => $this->orderItems($order),
            ...$this->orderMoney($order),
            'address' => $this->orderAddress($order),
            'recipient' => ['name' => $order->recipient_name, 'phone' => $order->recipient_phone],
            'payment' => $this->orderPayment($order, latestAttempt: true),
            'shipments' => $order->shipments->map(fn (Shipment $shipment) => [
                'id' => $shipment->id,
                'method' => $shipment->method->value,
                'method_label' => $shipment->method->label(),
                'carrier' => $shipment->carrier,
                'tracking_number' => $shipment->tracking_number,
                'tracking_url' => $shipment->tracking_url,
                'status' => $shipment->status->value,
                'status_label' => $shipment->status->label(),
                'assigned_to' => $shipment->assignee ? ['id' => $shipment->assignee->id, 'name' => $shipment->assignee->name] : null,
                'failure_reason' => $shipment->failure_reason,
                'created_at' => $this->dateTime($shipment->created_at),
                'events' => $shipment->events->map(fn (ShipmentEvent $event) => [
                    'status' => $event->status->value,
                    'status_label' => $event->status->label(),
                    'carrier_status' => $event->carrier_status,
                    'note' => $event->note,
                    'occurred_at' => $this->dateTime($event->occurred_at),
                    'actor' => $event->actor ? ['id' => $event->actor->id, 'name' => $event->actor->name] : null,
                ])->values(),
            ])->values(),
            'timeline' => $order->activity->sortBy('id')->map(fn (ActivityEvent $event) => [
                'id' => $event->id,
                'event' => $event->event,
                'event_label' => __('activity.'.$event->event),
                'status' => $event->to_status,
                'status_label' => $event->to_status ? StoreOrderStatus::tryFrom($event->to_status)?->label() : null,
                'note' => $event->meta['note'] ?? $event->meta['reason'] ?? null,
                'visibility' => self::enumValue($event->visibility),
                'actor' => $event->actor ? ['id' => $event->actor->id, 'name' => $event->actor->name] : null,
                'at' => $this->dateTime($event->created_at),
            ])->values(),
            'cancel_reason' => $order->cancel_reason,
            'next_actions' => $this->nextActions($order),
        ];
    }

    /** @return list<string> */
    private function nextActions(StoreOrder $order): array
    {
        $live = $order->shipments->first(fn (Shipment $s) => $s->status->isLive());
        $actions = [];
        if ($order->status === StoreOrderStatus::Confirmed) {
            $actions[] = 'prepare';
        }
        if (in_array($order->status, [StoreOrderStatus::Confirmed, StoreOrderStatus::Preparing], true) && $live === null) {
            $actions[] = 'ship';
            $actions[] = 'cancel';
        }
        if ($live?->status === ShipmentStatus::Pending && $live->method === ShipmentMethod::Carrier) {
            $actions[] = 'retry_shipment';
        }
        if ($live?->status === ShipmentStatus::InTransit) {
            $actions[] = 'mark_delivered';
            $actions[] = 'mark_failed';
        }

        return $actions;
    }
}
