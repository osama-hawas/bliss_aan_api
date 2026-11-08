<?php

namespace App\Http\Controllers\Api\V1\Admin\Providers;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Providers\ActivateApplicationRequest;
use App\Http\Resources\Api\V1\Admin\Providers\ApplicationDetailResource;
use App\Models\Provider;
use App\Services\Providers\ProviderReview;
use App\Traits\ApiResponse;

class ActivateApplicationController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ProviderReview $review) {}

    public function __invoke(ActivateApplicationRequest $request, Provider $provider)
    {
        $this->review->activate($provider, $request->user(), $request->validated('reason'), $request->validated('internal_note'));

        return $this->success(new ApplicationDetailResource(ApplicationDetailResource::load($provider->refresh())), __('Account activated.'));
    }
}
