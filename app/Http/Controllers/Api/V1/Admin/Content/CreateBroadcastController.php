<?php

namespace App\Http\Controllers\Api\V1\Admin\Content;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Content\BroadcastRequest;
use App\Http\Resources\Api\V1\Admin\Content\AdminBroadcastResource;
use App\Services\Content\BroadcastCampaigns;
use App\Traits\ApiResponse;

/**
 * `notifications.broadcast`: a draft campaign (D305).
 */
class CreateBroadcastController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly BroadcastCampaigns $campaigns) {}

    public function __invoke(BroadcastRequest $request)
    {
        $campaign = $this->campaigns->create($request->user(), $request->campaign());

        return $this->success(new AdminBroadcastResource($this->campaigns->find($campaign->id)), __('content.messages.created'), 201);
    }
}
