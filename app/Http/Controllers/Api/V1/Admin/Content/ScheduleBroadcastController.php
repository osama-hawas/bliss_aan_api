<?php

namespace App\Http\Controllers\Api\V1\Admin\Content;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Content\ScheduleBroadcastRequest;
use App\Http\Resources\Api\V1\Admin\Content\AdminBroadcastResource;
use App\Services\Content\BroadcastCampaigns;
use App\Traits\ApiResponse;

/**
 * `notifications.broadcast`: sends at a future time (D305).
 */
class ScheduleBroadcastController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly BroadcastCampaigns $campaigns) {}

    public function __invoke(ScheduleBroadcastRequest $request, int $broadcast)
    {
        $this->campaigns->schedule($this->campaigns->find($broadcast), $request->user(), $request->scheduledAt());

        return $this->success(new AdminBroadcastResource($this->campaigns->find($broadcast)), __('content.messages.broadcast_scheduled'));
    }
}
