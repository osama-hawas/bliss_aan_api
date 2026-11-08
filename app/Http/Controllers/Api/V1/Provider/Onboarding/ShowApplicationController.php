<?php

namespace App\Http\Controllers\Api\V1\Provider\Onboarding;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Provider\Onboarding\ApplicationResource;
use App\Services\Providers\ProviderOnboarding;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

/**
 * Her application and account state: under review, the reviewer's completion request, or a suspension.
 */
class ShowApplicationController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ProviderOnboarding $onboarding) {}

    public function __invoke(Request $request)
    {
        return $this->success(new ApplicationResource(ApplicationResource::load($this->onboarding->providerOf($request->user()))));
    }
}
