<?php

namespace App\Http\Controllers\Api\V1\Admin\Support;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Support\ChatReviewRequest;
use App\Http\Resources\Api\V1\Admin\Support\AdminChatMessageResource;
use App\Http\Resources\Api\V1\Admin\Support\AdminConversationResource;
use App\Models\Conversation;
use App\Models\ConversationAccessLog;
use App\Models\SupportTicket;
use App\Services\Support\ChatReview;
use App\Traits\ApiResponse;
use Illuminate\Support\Collection;

/**
 * Open a chat review with a reason; logged per page (Req 42, D260).
 */
class ReviewConversationController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ChatReview $review) {}

    public function __invoke(ChatReviewRequest $request, int $ticket, int $conversation)
    {
        return $this->respond($this->review->open($request->user(), SupportTicket::query()->findOrFail($ticket), $conversation,
            $request->validated('reason'), $request->validated('before'), (int) ($request->validated('per_page') ?? ChatReview::DEFAULT_PER_PAGE), $request->ip()));
    }

    /**
     * @param  array{0: ConversationAccessLog, 1: Collection, 2: bool, 3: Conversation}  $result
     */
    private function respond(array $result)
    {
        [$log, $messages, $hasMore, $conversation] = $result;

        return $this->success([
            'access_log_id' => $log->id,
            'conversation' => new AdminConversationResource($conversation),
            'messages' => $messages->map(fn ($m) => new AdminChatMessageResource($m, $conversation))->values(),
        ], null, 200, ['has_more' => $hasMore, 'next_before' => $hasMore ? $messages->last()?->id : null]);
    }
}
