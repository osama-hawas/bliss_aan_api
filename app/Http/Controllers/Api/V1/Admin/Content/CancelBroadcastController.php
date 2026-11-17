<?php

namespace App\Http\Controllers\Api\V1\Admin\Content;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Content\ContentReasonRequest;
use App\Http\Resources\Api\V1\Admin\Content\AdminBroadcastResource;
use App\Services\Content\BroadcastCampaigns;
use App\Traits\ApiResponse;

/**
 * `notifications.broadcast`: cancels a draft or scheduled campaign, with a reason.
 */
class CancelBroadcastController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly BroadcastCampaigns $campaigns) {}

    public function __invoke(ContentReasonRequest $request, int $broadcast)
    {
        $this->campaigns->cancel($this->campaigns->find($broadcast), $request->user(), $request->validated('reason'));

        return $this->success(new AdminBroadcastResource($this->campaigns->find($broadcast)), __('content.messages.broadcast_cancelled'));
    }
}
