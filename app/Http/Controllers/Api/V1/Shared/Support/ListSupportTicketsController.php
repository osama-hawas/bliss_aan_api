<?php

namespace App\Http\Controllers\Api\V1\Shared\Support;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Shared\Support\ListSupportTicketsRequest;
use App\Http\Resources\Api\V1\Shared\Support\SupportTicketResource;
use App\Services\Support\SupportTickets;
use App\Support\Pagination;
use App\Traits\ApiResponse;

/**
 * Her tickets, newest activity first (slice-12b-contract.md §5).
 */
class ListSupportTicketsController extends Controller
{
    use ApiResponse;

    public function __invoke(ListSupportTicketsRequest $request, SupportTickets $tickets)
    {
        return $this->success(SupportTicketResource::collection(
            $tickets->list($request->user(), $request->status(), Pagination::perPage($request)),
        ));
    }
}
