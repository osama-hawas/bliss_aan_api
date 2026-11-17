<?php

namespace App\Http\Controllers\Api\V1\Admin\Clients;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Clients\ListClientsRequest;
use App\Http\Resources\Api\V1\Admin\Clients\AdminClientResource;
use App\Services\Accounts\ClientAccounts;
use App\Support\Pagination;
use App\Traits\ApiResponse;

/**
 * `clients.view`: search by name, email or phone; filter by status and city.
 */
class ListClientsController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ClientAccounts $clients) {}

    public function __invoke(ListClientsRequest $request)
    {
        $page = $this->clients->list($request->filters(), $request->validated('sort'), Pagination::perPage($request));

        return $this->success(AdminClientResource::collection($page));
    }
}
