<?php

namespace App\Http\Controllers\Api\V1\Client\Chat;

use App\Http\Controllers\Concerns\RespondsWithChat;
use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

/**
 * One of her conversations, or 404 (slice-7-contract.md).
 */
class ShowConversationController extends Controller
{
    use ApiResponse, RespondsWithChat;

    public function __invoke(Request $request, int $conversation)
    {
        return $this->showConversation($request, $conversation);
    }
}
