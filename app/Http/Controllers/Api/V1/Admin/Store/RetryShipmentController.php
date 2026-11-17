<?php

namespace App\Http\Controllers\Api\V1\Admin\Store;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Admin\Store\AdminStoreOrderResource;
use App\Services\Store\ShipmentService;
use App\Services\Store\StoreOrderQueries;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

/**
 * Ask the carrier again for a pending shipment, same key (orders.update_status).
 */
class RetryShipmentController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ShipmentService $shipments, private readonly StoreOrderQueries $queries) {}

    public function __invoke(Request $request, int $shipment)
    {
        $order = $this->shipments->retryCarrier($this->queries->shipment($shipment), $request->user());

        return $this->success(new AdminStoreOrderResource($this->queries->adminOrder($order->id)));
    }
}
