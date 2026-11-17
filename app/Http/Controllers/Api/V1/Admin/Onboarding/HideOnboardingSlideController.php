<?php

namespace App\Http\Controllers\Api\V1\Admin\Onboarding;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Content\ContentReasonRequest;
use App\Http\Resources\Api\V1\Admin\Onboarding\AdminOnboardingSlideResource;
use App\Services\Content\OnboardingSlideManagement;
use App\Traits\ApiResponse;

/**
 * `content.manage`: hides a slide with a reason. Nothing is deleted (D301).
 */
class HideOnboardingSlideController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly OnboardingSlideManagement $slides) {}

    public function __invoke(ContentReasonRequest $request, int $slide)
    {
        $this->slides->hide($this->slides->find($slide), $request->user(), $request->validated('reason'));

        return $this->success(new AdminOnboardingSlideResource($this->slides->find($slide)), __('content.messages.hidden'));
    }
}
