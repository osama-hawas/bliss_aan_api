<?php

namespace App\Http\Controllers\Api\V1\Shared\Providers;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Shared\Providers\ProviderProfileResource;
use App\Services\Providers\ProviderDirectory;
use App\Traits\ApiResponse;

class ShowProviderController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ProviderDirectory $providers) {}

    /**
     * Public provider profile; 404 when she is not visible.
     */
    public function __invoke(int $provider)
    {
        return $this->success(new ProviderProfileResource($this->providers->profile($provider)));
    }
}
