<?php

namespace App\Http\Controllers\Api\V1\Admin\Content;

use App\Http\Controllers\Controller;
use App\Services\Content\BroadcastCampaigns;
use App\Traits\ApiResponse;

/**
 * `notifications.broadcast`: live recipient count, shown before sending (D305).
 */
class BroadcastAudienceController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly BroadcastCampaigns $campaigns) {}

    public function __invoke(int $broadcast)
    {
        return $this->success(['count' => $this->campaigns->audienceCount($this->campaigns->find($broadcast))]);
    }
}
