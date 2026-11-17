<?php

namespace App\Http\Controllers\Api\V1\Admin\Support;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Admin\Support\AdminTicketResource;
use App\Services\Support\StaffTicketQueries;
use App\Traits\ApiResponse;

/**
 * One ticket with the thread, internal notes, history and chat access log (`support.view`).
 */
class ShowTicketController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly StaffTicketQueries $queries) {}

    public function __invoke(int $ticket)
    {
        return $this->success((new AdminTicketResource($this->queries->find($ticket)))->detailed());
    }
}
