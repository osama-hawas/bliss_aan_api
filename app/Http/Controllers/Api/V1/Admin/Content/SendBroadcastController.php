<?php

namespace App\Http\Controllers\Api\V1\Admin\Content;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Content\SendBroadcastRequest;
use App\Http\Resources\Api\V1\Admin\Content\AdminBroadcastResource;
use App\Services\Content\BroadcastCampaigns;
use App\Traits\ApiResponse;

/**
 * `notifications.broadcast`: sends now; the confirmed count must still match (D305).
 */
class SendBroadcastController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly BroadcastCampaigns $campaigns) {}

    public function __invoke(SendBroadcastRequest $request, int $broadcast)
    {
        $this->campaigns->send($this->campaigns->find($broadcast), $request->user(), (int) $request->validated('expected_count'));

        return $this->success(new AdminBroadcastResource($this->campaigns->find($broadcast)), __('content.messages.broadcast_sending'));
    }
}
