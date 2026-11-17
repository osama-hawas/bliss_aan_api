<?php

namespace App\Http\Controllers\Api\V1\Admin\Content;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Admin\Content\AdminBroadcastResource;
use App\Services\Content\BroadcastCampaigns;
use App\Traits\ApiResponse;

/**
 * `notifications.broadcast`: one campaign.
 */
class ShowBroadcastController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly BroadcastCampaigns $campaigns) {}

    public function __invoke(int $broadcast)
    {
        return $this->success(new AdminBroadcastResource($this->campaigns->find($broadcast)));
    }
}
