<?php

namespace App\Http\Controllers\Api\V1\Provider\Chat;

use App\Http\Controllers\Concerns\RespondsWithChat;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Shared\Chat\MarkReadRequest;
use App\Traits\ApiResponse;

/**
 * Moves her read marker forward (slice-7-contract.md).
 */
class MarkReadController extends Controller
{
    use ApiResponse, RespondsWithChat;

    public function __invoke(MarkReadRequest $request, int $conversation)
    {
        return $this->markRead($request, $conversation);
    }
}
