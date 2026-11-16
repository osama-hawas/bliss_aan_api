<?php

namespace App\Http\Controllers\Concerns;

use App\Http\Requests\Api\V1\Shared\Chat\ListConversationsRequest;
use App\Http\Requests\Api\V1\Shared\Chat\ListMessagesRequest;
use App\Http\Requests\Api\V1\Shared\Chat\MarkReadRequest;
use App\Http\Requests\Api\V1\Shared\Chat\SendMessageRequest;
use App\Http\Resources\Api\V1\Shared\Chat\ConversationResource;
use App\Http\Resources\Api\V1\Shared\Chat\MessageResource;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\Chat\ChatAccess;
use App\Services\Chat\ChatMessenger;
use App\Services\Chat\ChatQueries;
use App\Support\Pagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The chat actions are the same for clients and providers (slice-7-contract.md); each audience keeps its own
 * single-action controllers and routes, and every lookup is scoped to the signed-in participant (404 otherwise).
 * Uses ApiResponse.
 */
trait RespondsWithChat
{
    protected function conversationResponse(Request $request, Conversation $conversation, ?string $message = null, int $status = 200): JsonResponse
    {
        $fresh = app(ChatAccess::class)->find($request->user(), $conversation->id);

        return $this->success(new ConversationResource($fresh), $message, $status);
    }

    protected function listConversations(ListConversationsRequest $request): JsonResponse
    {
        $page = app(ChatQueries::class)->list($request->user(), $request->validated('filter.status'), Pagination::perPage($request));

        return $this->success(ConversationResource::collection($page));
    }

    protected function showConversation(Request $request, int $id): JsonResponse
    {
        return $this->success(new ConversationResource(app(ChatAccess::class)->find($request->user(), $id)));
    }

    protected function listMessages(ListMessagesRequest $request, int $id): JsonResponse
    {
        $conversation = app(ChatAccess::class)->find($request->user(), $id);
        $queries = app(ChatQueries::class);
        [$rows, $more] = $queries->messages($conversation, $request->validated('before'), (int) ($request->validated('per_page') ?? 30));
        $marker = $queries->otherMarker($conversation, $request->user());
        $asClient = app(ChatAccess::class)->isClient($request->user(), $conversation);

        return $this->success(
            $rows->map(fn (Message $m) => (new MessageResource($m, $marker, $asClient))->resolve($request))->all(),
            null,
            200,
            ['has_more' => $more, 'next_before' => $more ? $rows->last()?->id : null],
        );
    }

    protected function sendMessage(SendMessageRequest $request, int $id): JsonResponse
    {
        $conversation = app(ChatAccess::class)->find($request->user(), $id);
        [$message, $isNew] = app(ChatMessenger::class)->send($request->user(), $conversation, $request->validated());
        $message->load(['media', 'offer.items', 'offer.provider']);
        $marker = app(ChatQueries::class)->otherMarker($conversation, $request->user());
        $asClient = app(ChatAccess::class)->isClient($request->user(), $conversation);

        return $this->success(new MessageResource($message, $marker, $asClient), $isNew ? __('Message sent.') : null, $isNew ? 201 : 200);
    }

    protected function markRead(MarkReadRequest $request, int $id): JsonResponse
    {
        $conversation = app(ChatAccess::class)->find($request->user(), $id);

        return $this->success(app(ChatMessenger::class)->markRead($request->user(), $conversation, (int) $request->validated('message_id')));
    }
}
