<?php

namespace App\Http\Controllers\Api\V1\Admin\Support;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Admin\Support\AdminConversationResource;
use App\Models\SupportTicket;
use App\Services\Support\ChatReview;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

/**
 * Conversations the ticket gives access to, without messages (`chats.view_for_ticket` + assignee, D260).
 */
class ListTicketConversationsController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ChatReview $review) {}

    public function __invoke(Request $request, int $ticket)
    {
        return $this->success(AdminConversationResource::collection(
            $this->review->conversations($request->user(), SupportTicket::query()->findOrFail($ticket))));
    }
}
