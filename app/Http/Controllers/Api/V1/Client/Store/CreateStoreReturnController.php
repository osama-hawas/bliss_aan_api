<?php

namespace App\Http\Controllers\Api\V1\Client\Store;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Client\Store\CreateStoreReturnRequest;
use App\Http\Resources\Api\V1\Client\Store\ClientStoreReturnResource;
use App\Services\Store\Returns\StoreReturnQueries;
use App\Services\Store\Returns\StoreReturnRequests;
use App\Traits\ApiResponse;

/**
 * Ask for a return of a delivered order (S23, §5.1): 201 new / 200 replay.
 */
class CreateStoreReturnController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly StoreReturnRequests $returns, private readonly StoreReturnQueries $queries) {}

    public function __invoke(CreateStoreReturnRequest $request, int $order)
    {
        [$return, $isNew] = $this->returns->create($request->user(), $order, $request->validated('idempotency_key'),
            $request->safe()->only(['reason_code', 'note', 'items', 'photo_media_ids']));

        return $this->success(new ClientStoreReturnResource($this->queries->clientReturn($request->user(), $return->id)), null, $isNew ? 201 : 200);
    }
}
