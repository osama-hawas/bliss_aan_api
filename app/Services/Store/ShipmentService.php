<?php

namespace App\Services\Store;

use App\Enums\ErrorCode;
use App\Enums\Permission;
use App\Enums\ShipmentMethod;
use App\Enums\ShipmentStatus;
use App\Enums\StoreOrderStatus;
use App\Enums\UserStatus;
use App\Enums\UserType;
use App\Exceptions\ApiException;
use App\Integrations\Shipping\Carrier;
use App\Integrations\Shipping\CarrierResult;
use App\Integrations\Shipping\ShipmentRequest;
use App\Jobs\RetryShipment;
use App\Models\Shipment;
use App\Models\ShipmentEvent;
use App\Models\StoreOrder;
use App\Models\StoreOrderItem;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Availability\DeadlockRetry;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Shipments of store orders (D201, D212; `slice-9b-contract.md` §8.2–§8.3). Staff choose carrier or internal delivery
 * per order. The carrier is called outside any transaction with the shipment's `request_key` (the staff
 * Idempotency-Key), so a retry never creates a second carrier shipment. Lock order: order → shipment.
 */
class ShipmentService
{
    public const MAX_ATTEMPTS = 3;

    public function __construct(
        private readonly Carrier $carrier,
        private readonly StoreOrderLifecycle $orders,
        private readonly AuditLogger $audit,
        private readonly DeadlockRetry $retry,
    ) {}

    /**
     * Creates the order's shipment. The same key returns the same shipment's order (replay); a key used for another
     * order → 409 DUPLICATE_REQUEST.
     */
    public function create(StoreOrder $order, User $staff, ShipmentMethod $method, ?int $assignedAdminId, string $key): StoreOrder
    {
        if ($existing = Shipment::query()->where('request_key', $key)->first()) {
            return $this->replay($existing, $order);
        }

        $assignee = $method === ShipmentMethod::Internal ? $this->officer($assignedAdminId) : null;

        try {
            $shipment = $this->retry->run(fn () => DB::transaction(function () use ($order, $staff, $method, $assignee, $key) {
                $order = $this->orders->lockOrder($order->id);
                if (! in_array($order->status, [StoreOrderStatus::Confirmed, StoreOrderStatus::Preparing], true)
                    || $order->shipments()->whereIn('status', [ShipmentStatus::Pending->value, ShipmentStatus::InTransit->value])->exists()) {
                    throw new ApiException(ErrorCode::InvalidState);
                }

                $shipment = Shipment::query()->create([
                    'store_order_id' => $order->id,
                    'method' => $method,
                    'carrier' => $method === ShipmentMethod::Carrier ? (string) config('services.shipping.driver') : null,
                    'status' => $method === ShipmentMethod::Internal ? ShipmentStatus::InTransit : ShipmentStatus::Pending,
                    'assigned_admin_id' => $assignee?->id,
                    'request_key' => $key,
                    'created_by' => $staff->id,
                ]);

                if ($method === ShipmentMethod::Internal) {
                    $this->event($shipment, ShipmentStatus::InTransit, $staff);
                    $this->orders->markShipped($order, $staff);
                }
                $this->audit->log('store_order_shipment_created', $staff, $order, null, null, ['method' => $method->value, 'assigned_admin_id' => $assignee?->id]);

                return $shipment;
            }), 'shipment.create', ['store_order_id' => $order->id]);
        } catch (QueryException $e) {
            if (str_contains($e->getMessage(), 'shipments_request_key_unique')) {
                return $this->replay(Shipment::query()->where('request_key', $key)->firstOrFail(), $order);
            }
            throw $e;
        }

        if ($method === ShipmentMethod::Carrier) {
            $this->callCarrier($shipment, $staff, throwOnFailure: true);
        }

        return $order->refresh();
    }

    /** Staff retry of a carrier shipment whose answer was unknown: same key, so never a second shipment. */
    public function retryCarrier(Shipment $shipment, ?User $staff): StoreOrder
    {
        if ($shipment->method !== ShipmentMethod::Carrier || $shipment->status !== ShipmentStatus::Pending) {
            throw new ApiException(ErrorCode::InvalidState);
        }
        $this->callCarrier($shipment, $staff, throwOnFailure: $staff !== null);

        return $shipment->order()->firstOrFail();
    }

    /**
     * Delivered / failed from in transit, by staff (`orders.update_status`) or the assigned officer
     * (`delivery.assigned`). Moves the order (§4.1). Repeating the current status is a no-op.
     */
    public function setStatus(Shipment $shipment, User $actor, ShipmentStatus $to, ?string $note, ?string $reason): StoreOrder
    {
        $this->authorize($shipment, $actor);
        if (! in_array($to, [ShipmentStatus::Delivered, ShipmentStatus::Failed], true)) {
            throw new ApiException(ErrorCode::InvalidState);
        }
        if ($to === ShipmentStatus::Failed && ($reason === null || trim($reason) === '')) {
            throw ValidationException::withMessages(['reason' => __('store.shipment_failed_reason')]);
        }

        return $this->retry->run(fn () => DB::transaction(function () use ($shipment, $actor, $to, $note, $reason) {
            $order = $this->orders->lockOrder($shipment->store_order_id);
            $shipment = Shipment::query()->whereKey($shipment->id)->lockForUpdate()->firstOrFail();
            if ($shipment->status === $to) {
                return $order;
            }
            if ($shipment->status !== ShipmentStatus::InTransit) {
                throw new ApiException(ErrorCode::InvalidState);
            }

            $this->apply($order, $shipment, $to, $actor, $to === ShipmentStatus::Failed ? $reason : $note);
            $this->audit->log('shipment_'.$to->value, $actor, $shipment, $reason ?? $note);

            return $order;
        }), 'shipment.status', ['shipment_id' => $shipment->id]);
    }

    /**
     * Polls the carrier for in-transit carrier shipments (`shipments:sync`). Each carrier status is stored once
     * (`external_event_id`); delivered / failed move the shipment and the order.
     */
    public function sync(): int
    {
        $moved = 0;
        Shipment::query()->where('method', ShipmentMethod::Carrier)->where('status', ShipmentStatus::InTransit)
            ->whereNotNull('tracking_number')->orderBy('id')
            ->chunkById(100, function ($shipments) use (&$moved) {
                foreach ($shipments as $shipment) {
                    $result = $this->carrier->track($shipment->tracking_number);
                    $to = ShipmentStatus::tryFrom((string) $result->shipmentStatus);
                    if ($result->status !== 'accepted' || $to === null || $result->carrierStatus === null) {
                        continue;
                    }
                    $moved += $this->recordCarrierStatus($shipment, $to, $result) ? 1 : 0;
                }
            });

        return $moved;
    }

    /** Internal staff who may take deliveries, for the ship dialog. */
    public function officers()
    {
        return User::query()->where('type', UserType::Admin)->where('status', UserStatus::Active)
            ->permission(Permission::DeliveryAssigned->value)->orderBy('name')->get(['id', 'name']);
    }

    private function callCarrier(Shipment $shipment, ?User $staff, bool $throwOnFailure): void
    {
        $order = $shipment->order()->with('items')->firstOrFail();
        $result = $this->carrier->createShipment(new ShipmentRequest(
            reference: $order->reference,
            recipientName: $order->recipient_name,
            recipientPhone: $order->recipient_phone,
            address: $order->address,
            items: $order->items->map(fn (StoreOrderItem $item) => ['name' => $item->localizedName(), 'quantity' => $item->quantity])->all(),
        ), $shipment->request_key);

        $outcome = $this->retry->run(fn () => DB::transaction(function () use ($shipment, $staff, $result) {
            $order = $this->orders->lockOrder($shipment->store_order_id);
            $shipment = Shipment::query()->whereKey($shipment->id)->lockForUpdate()->firstOrFail();
            if ($shipment->status !== ShipmentStatus::Pending) {
                return 'done';
            }

            if ($result->status === 'accepted') {
                $shipment->update(['status' => ShipmentStatus::InTransit, 'tracking_number' => $result->trackingNumber, 'tracking_url' => $result->trackingUrl, 'attempts' => $shipment->attempts + 1]);
                $this->event($shipment, ShipmentStatus::InTransit, $staff, $result->carrierStatus, $result->raw);
                $this->orders->markShipped($order, $staff);

                return 'accepted';
            }
            if ($result->status === 'failed') {
                $shipment->update(['status' => ShipmentStatus::Failed, 'failure_reason' => (string) ($result->raw['error'] ?? 'carrier_failed'), 'attempts' => $shipment->attempts + 1]);
                $this->event($shipment, ShipmentStatus::Failed, $staff, $result->carrierStatus, $result->raw);

                return 'failed';
            }
            $shipment->update(['attempts' => $shipment->attempts + 1]);

            return $shipment->attempts < self::MAX_ATTEMPTS ? 'retry' : 'unknown';
        }), 'shipment.carrier', ['shipment_id' => $shipment->id]);

        if ($outcome === 'retry') {
            RetryShipment::dispatch($shipment->id)->delay(now()->addMinute());
        }
        if ($outcome === 'failed' && $throwOnFailure) {
            throw new ApiException(ErrorCode::CarrierFailed);
        }
    }

    private function recordCarrierStatus(Shipment $shipment, ShipmentStatus $to, CarrierResult $result): bool
    {
        return $this->retry->run(fn () => DB::transaction(function () use ($shipment, $to, $result) {
            $order = $this->orders->lockOrder($shipment->store_order_id);
            $shipment = Shipment::query()->whereKey($shipment->id)->lockForUpdate()->firstOrFail();
            $inserted = ShipmentEvent::query()->insertOrIgnore([
                'shipment_id' => $shipment->id,
                'external_event_id' => $shipment->tracking_number.':'.$result->carrierStatus,
                'status' => $to->value,
                'carrier_status' => $result->carrierStatus,
                'occurred_at' => now(),
                'raw' => json_encode($result->raw),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            if ($inserted === 0 || $shipment->status !== ShipmentStatus::InTransit || $to === ShipmentStatus::InTransit) {
                return false;
            }
            $this->apply($order, $shipment, $to, null, null, recordEvent: false);

            return true;
        }), 'shipment.sync', ['shipment_id' => $shipment->id]);
    }

    private function apply(StoreOrder $order, Shipment $shipment, ShipmentStatus $to, ?User $actor, ?string $note, bool $recordEvent = true): void
    {
        $shipment->update([
            'status' => $to,
            'delivered_at' => $to === ShipmentStatus::Delivered ? now() : $shipment->delivered_at,
            'failure_reason' => $to === ShipmentStatus::Failed ? $note : $shipment->failure_reason,
        ]);
        if ($recordEvent) {
            $this->event($shipment, $to, $actor, note: $note);
        }

        $to === ShipmentStatus::Delivered
            ? $this->orders->markDelivered($order, $actor)
            : $this->orders->deliveryFailed($order, $actor, $note);
    }

    private function event(Shipment $shipment, ShipmentStatus $status, ?User $actor, ?string $carrierStatus = null, array $raw = [], ?string $note = null): void
    {
        ShipmentEvent::query()->create([
            'shipment_id' => $shipment->id,
            'external_event_id' => 'staff:'.Str::uuid(),
            'status' => $status,
            'carrier_status' => $carrierStatus,
            'note' => $note,
            'occurred_at' => now(),
            'raw' => $raw ?: null,
            'actor_id' => $actor?->id,
        ]);
    }

    private function authorize(Shipment $shipment, User $actor): void
    {
        if ($actor->can(Permission::OrdersUpdateStatus->value)) {
            return;
        }
        if ($actor->can(Permission::DeliveryAssigned->value) && $shipment->assigned_admin_id === $actor->id) {
            return;
        }

        throw new ApiException(ErrorCode::NotFound); // never confirm a shipment she may not see
    }

    private function officer(?int $id): User
    {
        if ($id === null) {
            throw ValidationException::withMessages(['assigned_admin_id' => __('store.internal_requires_officer')]);
        }

        return $this->officers()->firstWhere('id', $id)
            ?? throw ValidationException::withMessages(['assigned_admin_id' => __('store.officer_invalid')]);
    }

    private function replay(Shipment $existing, StoreOrder $order): StoreOrder
    {
        if ($existing->store_order_id !== $order->id) {
            throw new ApiException(ErrorCode::DuplicateRequest);
        }

        return $order->refresh();
    }
}
