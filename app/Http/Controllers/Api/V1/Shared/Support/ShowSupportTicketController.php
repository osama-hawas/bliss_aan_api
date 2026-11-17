<?php

namespace App\Http\Controllers\Api\V1\Shared\Support;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Shared\Support\SupportTicketResource;
use App\Services\Support\SupportTickets;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

/**
 * One of her tickets with its public messages; another user's ticket is a 404 (D159).
 */
class ShowSupportTicketController extends Controller
{
    use ApiResponse;

    public function __invoke(Request $request, SupportTickets $tickets, int $ticket)
    {
        return $this->success(new SupportTicketResource($tickets->find($request->user(), $ticket)));
    }
}
