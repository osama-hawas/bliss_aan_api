<?php

namespace App\Http\Controllers\Api\V1\Admin\Onboarding;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Admin\Onboarding\AdminOnboardingSlideResource;
use App\Services\Content\OnboardingSlideManagement;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

/**
 * `content.manage`: makes a slide visible in its app; both languages required (D301).
 */
class PublishOnboardingSlideController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly OnboardingSlideManagement $slides) {}

    public function __invoke(Request $request, int $slide)
    {
        $this->slides->show($this->slides->find($slide), $request->user());

        return $this->success(new AdminOnboardingSlideResource($this->slides->find($slide)), __('content.messages.shown'));
    }
}
