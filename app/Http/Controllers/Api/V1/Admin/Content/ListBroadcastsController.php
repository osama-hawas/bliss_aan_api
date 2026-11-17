<?php

namespace App\Http\Controllers\Api\V1\Admin\Content;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Content\ListBroadcastsRequest;
use App\Http\Resources\Api\V1\Admin\Content\AdminBroadcastResource;
use App\Services\Content\BroadcastCampaigns;
use App\Support\Pagination;
use App\Traits\ApiResponse;

/**
 * `notifications.broadcast`: campaigns (D305).
 */
class ListBroadcastsController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly BroadcastCampaigns $campaigns) {}

    public function __invoke(ListBroadcastsRequest $request)
    {
        return $this->success(AdminBroadcastResource::collection($this->campaigns->list($request->filters(), $request->validated('sort'), Pagination::perPage($request))));
    }
}
