<?php

namespace App\Http\Controllers\Api\V1\Admin\Store;

use App\Enums\ShipmentMethod;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Store\CreateShipmentRequest;
use App\Http\Resources\Api\V1\Admin\Store\AdminStoreOrderResource;
use App\Services\Store\ShipmentService;
use App\Services\Store\StoreOrderQueries;
use App\Traits\ApiResponse;

/**
 * Ship an order by carrier or internal delivery (orders.update_status, D201). Idempotency-Key = request key.
 */
class CreateShipmentController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ShipmentService $shipments, private readonly StoreOrderQueries $queries) {}

    public function __invoke(CreateShipmentRequest $request, int $order)
    {
        $assigned = $request->validated('assigned_admin_id');
        $this->shipments->create(
            $this->queries->adminOrder($order),
            $request->user(),
            ShipmentMethod::from($request->validated('method')),
            $assigned === null ? null : (int) $assigned,
            $request->validated('idempotency_key'),
        );

        return $this->success(new AdminStoreOrderResource($this->queries->adminOrder($order)), null, 201);
    }
}
