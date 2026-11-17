<?php

namespace App\Http\Controllers\Api\V1\Admin\Clients;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Clients\ListDeletionRequestsRequest;
use App\Http\Resources\Api\V1\Admin\Clients\AdminDeletionRequestResource;
use App\Services\Account\AccountDeletionQueries;
use App\Support\Pagination;
use App\Traits\ApiResponse;

/**
 * `clients.edit` (S22, D336): client and provider deletion requests with their live obligations.
 */
class ListDeletionRequestsController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly AccountDeletionQueries $queries) {}

    public function __invoke(ListDeletionRequestsRequest $request)
    {
        return $this->success(AdminDeletionRequestResource::collection(
            $this->queries->list($request->filters(), Pagination::perPage($request))));
    }
}
