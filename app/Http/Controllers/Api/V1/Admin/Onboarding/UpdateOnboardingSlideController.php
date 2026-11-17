<?php

namespace App\Http\Controllers\Api\V1\Admin\Onboarding;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Onboarding\OnboardingSlideRequest;
use App\Http\Resources\Api\V1\Admin\Onboarding\AdminOnboardingSlideResource;
use App\Services\Content\OnboardingSlideManagement;
use App\Traits\ApiResponse;

/**
 * `content.manage`: edits the text and image of a slide; a visible slide keeps both languages (D301).
 */
class UpdateOnboardingSlideController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly OnboardingSlideManagement $slides) {}

    public function __invoke(OnboardingSlideRequest $request, int $slide)
    {
        $this->slides->update($this->slides->find($slide), $request->user(), $request->slide());

        return $this->success(new AdminOnboardingSlideResource($this->slides->find($slide)), __('content.messages.updated'));
    }
}
