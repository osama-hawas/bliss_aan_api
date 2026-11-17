<?php

namespace App\Http\Controllers\Api\V1\Shared\Onboarding;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Shared\Content\ContentAppRequest;
use App\Http\Resources\Api\V1\Shared\Onboarding\OnboardingSlideResource;
use App\Services\Content\OnboardingSlideManagement;
use App\Traits\ApiResponse;

/**
 * `GET shared/onboarding?app=client|provider`: visible slides of that app in order (Slice 25, D364). Public; the app is
 * chosen by `app`, never by the token (D158).
 */
class ListOnboardingSlidesController extends Controller
{
    use ApiResponse;

    public function __invoke(ContentAppRequest $request, OnboardingSlideManagement $slides)
    {
        return $this->success(OnboardingSlideResource::collection($slides->visible($request->app())));
    }
}
