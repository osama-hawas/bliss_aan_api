<?php

namespace App\Http\Controllers\Api\V1\Provider\ServiceRequests;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Provider\ServiceRequests\DeclineReasonResource;
use App\Models\DeclineReason;
use App\Traits\ApiResponse;

class ListDeclineReasonsController extends Controller
{
    use ApiResponse;

    public function __invoke()
    {
        return $this->success(DeclineReasonResource::collection(DeclineReason::query()->active()->orderBy('sort')->orderBy('id')->get()));
    }
}
