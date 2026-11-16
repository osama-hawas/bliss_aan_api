<?php

namespace App\Http\Controllers\Api\V1\Provider\Chat;

use App\Http\Controllers\Concerns\RespondsWithChat;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Shared\Chat\ListConversationsRequest;
use App\Traits\ApiResponse;

/**
 * Her conversations, newest activity first (slice-7-contract.md).
 */
class ListConversationsController extends Controller
{
    use ApiResponse, RespondsWithChat;

    public function __invoke(ListConversationsRequest $request)
    {
        return $this->listConversations($request);
    }
}
