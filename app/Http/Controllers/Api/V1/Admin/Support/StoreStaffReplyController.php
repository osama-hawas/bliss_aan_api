<?php

namespace App\Http\Controllers\Api\V1\Admin\Support;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Support\StaffMessageRequest;
use App\Http\Resources\Api\V1\Admin\Support\AdminTicketResource;
use App\Services\Support\StaffTicketQueries;
use App\Services\Support\StaffTickets;
use App\Traits\ApiResponse;

/**
 * Staff reply shown to the user as the support team (`support.reply`, D258, D161).
 */
class StoreStaffReplyController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly StaffTicketQueries $queries, private readonly StaffTickets $tickets) {}

    public function __invoke(StaffMessageRequest $request, int $ticket)
    {
        [, $isNew] = $this->tickets->write($request->user(), $ticket, $request->validated('body'), $request->validated('client_uuid'), false);

        return $this->success((new AdminTicketResource($this->queries->find($ticket)))->detailed(),
            $isNew ? __('support.staff_replied') : null, $isNew ? 201 : 200);
    }
}
