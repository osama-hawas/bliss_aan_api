<?php

namespace App\Http\Controllers\Api\V1\Admin\Store;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Store\NoteRequest;
use App\Http\Resources\Api\V1\Admin\Store\AdminStoreOrderResource;
use App\Services\Store\StoreOrderLifecycle;
use App\Services\Store\StoreOrderQueries;
use App\Traits\ApiResponse;

/**
 * confirmed → preparing (orders.update_status).
 */
class PrepareStoreOrderController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly StoreOrderLifecycle $lifecycle, private readonly StoreOrderQueries $queries) {}

    public function __invoke(NoteRequest $request, int $order)
    {
        $this->lifecycle->prepare($this->queries->adminOrder($order), $request->user(), $request->validated('note'));

        return $this->success(new AdminStoreOrderResource($this->queries->adminOrder($order)));
    }
}
