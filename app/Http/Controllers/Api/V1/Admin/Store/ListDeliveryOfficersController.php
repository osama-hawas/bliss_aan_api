<?php

namespace App\Http\Controllers\Api\V1\Admin\Store;

use App\Http\Controllers\Controller;
use App\Services\Store\ShipmentService;
use App\Traits\ApiResponse;

/**
 * Staff who can take internal deliveries, for the ship dialog (orders.update_status).
 */
class ListDeliveryOfficersController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ShipmentService $shipments) {}

    public function __invoke()
    {
        return $this->success($this->shipments->officers()->map(fn ($user) => ['id' => $user->id, 'name' => $user->name])->values());
    }
}
