<?php

namespace App\Http\Controllers\Api\V1\Provider\Chat;

use App\Http\Controllers\Concerns\RespondsWithChat;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Shared\Chat\ListMessagesRequest;
use App\Traits\ApiResponse;

/**
 * Messages, newest first, cursor `before`; never marks them read (slice-7-contract.md).
 */
class ListMessagesController extends Controller
{
    use ApiResponse, RespondsWithChat;

    public function __invoke(ListMessagesRequest $request, int $conversation)
    {
        return $this->listMessages($request, $conversation);
    }
}
