<?php

namespace App\Http\Controllers\Api\V1\Admin\Onboarding;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Onboarding\MoveOnboardingSlideRequest;
use App\Http\Resources\Api\V1\Admin\Onboarding\AdminOnboardingSlideResource;
use App\Services\Content\OnboardingSlideManagement;
use App\Traits\ApiResponse;

/**
 * `content.manage`: moves a slide to a position inside its app (D363).
 */
class MoveOnboardingSlideController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly OnboardingSlideManagement $slides) {}

    public function __invoke(MoveOnboardingSlideRequest $request, int $slide)
    {
        $this->slides->move($this->slides->find($slide), $request->user(), (int) $request->validated('position'));

        return $this->success(new AdminOnboardingSlideResource($this->slides->find($slide)), __('content.messages.moved'));
    }
}
