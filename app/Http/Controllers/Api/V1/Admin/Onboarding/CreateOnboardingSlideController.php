<?php

namespace App\Http\Controllers\Api\V1\Admin\Onboarding;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Onboarding\OnboardingSlideRequest;
use App\Http\Resources\Api\V1\Admin\Onboarding\AdminOnboardingSlideResource;
use App\Services\Content\OnboardingSlideManagement;
use App\Traits\ApiResponse;

/**
 * `content.manage`: creates a hidden slide at the end of its app (D360).
 */
class CreateOnboardingSlideController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly OnboardingSlideManagement $slides) {}

    public function __invoke(OnboardingSlideRequest $request)
    {
        $row = $this->slides->create($request->user(), $request->slide());

        return $this->success(new AdminOnboardingSlideResource($this->slides->find($row->id)), __('content.messages.created'), 201);
    }
}
