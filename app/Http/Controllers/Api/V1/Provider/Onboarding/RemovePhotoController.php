<?php

namespace App\Http\Controllers\Api\V1\Provider\Onboarding;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Provider\Onboarding\ApplicationResource;
use App\Services\Providers\ProviderOnboarding;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

class RemovePhotoController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ProviderOnboarding $onboarding) {}

    public function __invoke(Request $request, int $photo)
    {
        $provider = $this->onboarding->providerOf($request->user());
        $this->onboarding->removePhoto($provider, $provider->portfolioItems()->findOrFail($photo));

        return $this->success(new ApplicationResource(ApplicationResource::load($provider->refresh())), __('Photo removed.'));
    }
}
