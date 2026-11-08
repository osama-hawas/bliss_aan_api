<?php

namespace App\Http\Controllers\Api\V1\Provider\Onboarding;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Provider\Onboarding\UpdateApplicationRequest;
use App\Http\Resources\Api\V1\Provider\Onboarding\ApplicationResource;
use App\Services\Providers\ProviderOnboarding;
use App\Traits\ApiResponse;

class UpdateApplicationController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ProviderOnboarding $onboarding) {}

    public function __invoke(UpdateApplicationRequest $request)
    {
        $provider = $this->onboarding->update($this->onboarding->providerOf($request->user()), $request->validated());

        return $this->success(new ApplicationResource(ApplicationResource::load($provider)), __('Application saved.'));
    }
}
