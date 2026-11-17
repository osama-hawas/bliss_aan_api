<?php

namespace App\Http\Controllers\Api\V1\Shared\Support;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Shared\Support\StoreSupportTicketRequest;
use App\Http\Resources\Api\V1\Shared\Support\SupportTicketResource;
use App\Services\Support\SupportTickets;
use App\Traits\ApiResponse;

/**
 * Opens a ticket with its first message; a repeated `Idempotency-Key` returns the same ticket (D162).
 */
class StoreSupportTicketController extends Controller
{
    use ApiResponse;

    public function __invoke(StoreSupportTicketRequest $request, SupportTickets $tickets)
    {
        $user = $request->user();
        [$ticket, $isNew] = $tickets->create($user, $request->validated('idempotency_key'), $request->body());

        return $this->success(
            new SupportTicketResource($tickets->find($user, $ticket->id)),
            $isNew ? __('support.ticket_opened') : null,
            $isNew ? 201 : 200,
        );
    }
}
