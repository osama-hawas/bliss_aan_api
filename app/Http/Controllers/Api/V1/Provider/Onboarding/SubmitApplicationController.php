<?php

namespace App\Http\Controllers\Api\V1\Provider\Onboarding;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Provider\Onboarding\ApplicationResource;
use App\Services\Providers\ProviderOnboarding;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

/**
 * Sends the application (or the completed one) to review. A second submit while under review is `409`.
 */
class SubmitApplicationController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ProviderOnboarding $onboarding) {}

    public function __invoke(Request $request)
    {
        $provider = $this->onboarding->submit($this->onboarding->providerOf($request->user()), $request->user());

        return $this->success(new ApplicationResource(ApplicationResource::load($provider)), __('Application sent for review.'));
    }
}
