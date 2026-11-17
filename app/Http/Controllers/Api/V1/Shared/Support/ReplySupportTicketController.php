<?php

namespace App\Http\Controllers\Api\V1\Shared\Support;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Shared\Support\ReplySupportTicketRequest;
use App\Http\Resources\Api\V1\Shared\Support\SupportTicketResource;
use App\Services\Support\SupportTickets;
use App\Traits\ApiResponse;

/**
 * Her reply (D160); answers with the updated ticket. A repeated `client_uuid` returns it without a second message.
 */
class ReplySupportTicketController extends Controller
{
    use ApiResponse;

    public function __invoke(ReplySupportTicketRequest $request, SupportTickets $tickets, int $ticket)
    {
        $user = $request->user();
        [, $isNew] = $tickets->reply($user, $ticket, $request->validated());

        return $this->success(
            new SupportTicketResource($tickets->find($user, $ticket)),
            $isNew ? __('support.reply_sent') : null,
            $isNew ? 201 : 200,
        );
    }
}
