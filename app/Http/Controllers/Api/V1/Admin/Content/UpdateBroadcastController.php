<?php

namespace App\Http\Controllers\Api\V1\Admin\Content;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Content\BroadcastRequest;
use App\Http\Resources\Api\V1\Admin\Content\AdminBroadcastResource;
use App\Services\Content\BroadcastCampaigns;
use App\Traits\ApiResponse;

/**
 * `notifications.broadcast`: edits a draft only.
 */
class UpdateBroadcastController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly BroadcastCampaigns $campaigns) {}

    public function __invoke(BroadcastRequest $request, int $broadcast)
    {
        $this->campaigns->update($this->campaigns->find($broadcast), $request->user(), $request->campaign());

        return $this->success(new AdminBroadcastResource($this->campaigns->find($broadcast)), __('content.messages.updated'));
    }
}
