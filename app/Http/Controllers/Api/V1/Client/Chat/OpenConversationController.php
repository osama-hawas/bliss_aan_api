<?php

namespace App\Http\Controllers\Api\V1\Client\Chat;

use App\Http\Controllers\Concerns\RespondsWithChat;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Shared\Chat\OpenConversationRequest;
use App\Services\Chat\ChatAccess;
use App\Traits\ApiResponse;

/**
 * Get-or-create her conversation with a provider on her request (slice-7-contract.md §2).
 */
class OpenConversationController extends Controller
{
    use ApiResponse, RespondsWithChat;

    public function __construct(private readonly ChatAccess $access) {}

    public function __invoke(OpenConversationRequest $request, int $serviceRequest)
    {
        $conversation = $this->access->openAsClient($request->user(), $serviceRequest, (int) $request->validated('provider_id'));

        return $this->conversationResponse($request, $conversation);
    }
}
