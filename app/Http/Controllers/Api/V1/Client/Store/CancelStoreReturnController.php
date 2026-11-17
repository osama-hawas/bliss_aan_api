<?php

namespace App\Http\Controllers\Api\V1\Client\Store;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Client\Store\ClientStoreReturnResource;
use App\Services\Store\Returns\StoreReturnQueries;
use App\Services\Store\Returns\StoreReturnRequests;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

/**
 * She cancels her return while it is `requested` (S23, §5.4).
 */
class CancelStoreReturnController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly StoreReturnRequests $returns, private readonly StoreReturnQueries $queries) {}

    public function __invoke(Request $request)
    {
        $orderId = $request->route('order') === null ? null : (int) $request->route('order');
        $returnId = (int) $request->route('return');
        $this->queries->clientReturn($request->user(), $returnId, $orderId); // foreign / other order → 404
        $this->returns->cancel($request->user(), $returnId);

        return $this->success(new ClientStoreReturnResource($this->queries->clientReturn($request->user(), $returnId)));
    }
}
