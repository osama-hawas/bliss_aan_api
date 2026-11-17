<?php

namespace App\Http\Controllers\Api\V1\Admin\Support;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Admin\Support\AdminTicketResource;
use App\Services\Support\StaffTicketQueries;
use App\Services\Support\StaffTickets;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

/**
 * Take an unassigned ticket (`support.reply`, D259).
 */
class TakeTicketController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly StaffTicketQueries $queries, private readonly StaffTickets $tickets) {}

    public function __invoke(Request $request, int $ticket)
    {
        $this->tickets->take($request->user(), $ticket);

        return $this->success((new AdminTicketResource($this->queries->find($ticket)))->detailed(), __('support.assigned'));
    }
}
