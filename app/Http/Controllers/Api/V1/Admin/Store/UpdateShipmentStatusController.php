<?php

namespace App\Http\Controllers\Api\V1\Admin\Store;

use App\Enums\Permission;
use App\Enums\ShipmentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Store\ShipmentStatusRequest;
use App\Http\Resources\Api\V1\Admin\Store\AdminStoreOrderResource;
use App\Http\Resources\Api\V1\Admin\Store\DeliveryResource;
use App\Services\Store\ShipmentService;
use App\Services\Store\StoreOrderQueries;
use App\Traits\ApiResponse;

/**
 * Delivered / failed: staff with orders.update_status, or the assigned officer (delivery.assigned), who gets her delivery back.
 */
class UpdateShipmentStatusController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ShipmentService $shipments, private readonly StoreOrderQueries $queries) {}

    public function __invoke(ShipmentStatusRequest $request, int $shipment)
    {
        $order = $this->shipments->setStatus(
            $this->queries->shipment($shipment),
            $request->user(),
            ShipmentStatus::from($request->validated('status')),
            $request->validated('note'),
            $request->validated('reason'),
        );

        // A delivery officer without orders.view sees her delivery, never the whole order (client contact, payment, notes).
        if (! $request->user()->can(Permission::OrdersView->value)) {
            return $this->success(new DeliveryResource($this->queries->shipment($shipment)->load('order.items')));
        }

        return $this->success(new AdminStoreOrderResource($this->queries->adminOrder($order->id)));
    }
}
