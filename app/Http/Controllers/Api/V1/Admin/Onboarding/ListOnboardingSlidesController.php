<?php

namespace App\Http\Controllers\Api\V1\Admin\Onboarding;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Onboarding\ListOnboardingSlidesRequest;
use App\Http\Resources\Api\V1\Admin\Onboarding\AdminOnboardingSlideResource;
use App\Services\Content\OnboardingSlideManagement;
use App\Traits\ApiResponse;

/**
 * `content.manage`: slides of one app (or both) in display order (Slice 25, D362).
 */
class ListOnboardingSlidesController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly OnboardingSlideManagement $slides) {}

    public function __invoke(ListOnboardingSlidesRequest $request)
    {
        return $this->success(AdminOnboardingSlideResource::collection($this->slides->list($request->app(), $request->status())));
    }
}
