<?php

namespace App\Http\Controllers\Api\V1\Provider\Onboarding;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Provider\Onboarding\AddPhotoRequest;
use App\Http\Resources\Api\V1\Provider\Onboarding\ApplicationResource;
use App\Services\Providers\ProviderOnboarding;
use App\Traits\ApiResponse;

class AddPhotoController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ProviderOnboarding $onboarding) {}

    public function __invoke(AddPhotoRequest $request)
    {
        $provider = $this->onboarding->providerOf($request->user());
        $this->onboarding->addPhoto($provider, $request->user(), (int) $request->validated('media_id'), $request->validated('caption'));

        return $this->success(new ApplicationResource(ApplicationResource::load($provider->refresh())), __('Photo added.'), 201);
    }
}
