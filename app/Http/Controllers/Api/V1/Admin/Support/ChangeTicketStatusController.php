<?php

namespace App\Http\Controllers\Api\V1\Admin\Support;

use App\Enums\TicketStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Support\TicketStatusRequest;
use App\Http\Resources\Api\V1\Admin\Support\AdminTicketResource;
use App\Services\Support\StaffTicketQueries;
use App\Services\Support\StaffTickets;
use App\Traits\ApiResponse;

/**
 * A staff status move (`support.reply`, D258).
 */
class ChangeTicketStatusController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly StaffTicketQueries $queries, private readonly StaffTickets $tickets) {}

    public function __invoke(TicketStatusRequest $request, int $ticket)
    {
        $this->tickets->changeStatus($request->user(), $ticket, TicketStatus::from($request->validated('status')), $request->validated('reason'));

        return $this->success((new AdminTicketResource($this->queries->find($ticket)))->detailed(), __('support.status_changed'));
    }
}
