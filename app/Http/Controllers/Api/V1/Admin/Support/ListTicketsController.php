<?php

namespace App\Http\Controllers\Api\V1\Admin\Support;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Support\ListTicketsRequest;
use App\Http\Resources\Api\V1\Admin\Support\AdminTicketResource;
use App\Services\Support\StaffTicketQueries;
use App\Support\Pagination;
use App\Traits\ApiResponse;

/**
 * Ticket queue (`support.view`, §6).
 */
class ListTicketsController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly StaffTicketQueries $queries) {}

    public function __invoke(ListTicketsRequest $request)
    {
        return $this->success(AdminTicketResource::collection(
            $this->queries->list($request->user(), $request->filters(), $request->validated('sort'), Pagination::perPage($request))));
    }
}
