<?php

namespace App\Http\Controllers\Api\V1\Admin\Support;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Support\AssignTicketRequest;
use App\Http\Resources\Api\V1\Admin\Support\AdminTicketResource;
use App\Services\Support\StaffTicketQueries;
use App\Services\Support\StaffTickets;
use App\Traits\ApiResponse;

/**
 * Assign or unassign (`support.assign`, D259).
 */
class AssignTicketController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly StaffTicketQueries $queries, private readonly StaffTickets $tickets) {}

    public function __invoke(AssignTicketRequest $request, int $ticket)
    {
        $assignee = $request->validated('assignee_id');
        $this->tickets->assign($request->user(), $ticket, $assignee === null ? null : (int) $assignee, $request->validated('reason'));

        return $this->success((new AdminTicketResource($this->queries->find($ticket)))->detailed(), __('support.assigned'));
    }
}
