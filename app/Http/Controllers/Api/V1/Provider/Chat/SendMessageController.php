<?php

namespace App\Http\Controllers\Api\V1\Provider\Chat;

use App\Http\Controllers\Concerns\RespondsWithChat;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Shared\Chat\SendMessageRequest;
use App\Traits\ApiResponse;

/**
 * Sends a message; 201 new, 200 replay of the same client_uuid (slice-7-contract.md).
 */
class SendMessageController extends Controller
{
    use ApiResponse, RespondsWithChat;

    public function __invoke(SendMessageRequest $request, int $conversation)
    {
        return $this->sendMessage($request, $conversation);
    }
}
