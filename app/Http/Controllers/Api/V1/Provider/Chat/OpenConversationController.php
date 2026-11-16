<?php

namespace App\Http\Controllers\Api\V1\Provider\Chat;

use App\Http\Controllers\Concerns\RespondsWithChat;
use App\Http\Controllers\Controller;
use App\Services\Chat\ChatAccess;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

/**
 * Get-or-create her conversation with the client on a request she received (slice-7-contract.md §2).
 */
class OpenConversationController extends Controller
{
    use ApiResponse, RespondsWithChat;

    public function __construct(private readonly ChatAccess $access) {}

    public function __invoke(Request $request, int $serviceRequest)
    {
        return $this->conversationResponse($request, $this->access->openAsProvider($request->user(), $serviceRequest));
    }
}
