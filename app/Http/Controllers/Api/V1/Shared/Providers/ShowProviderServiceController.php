<?php

namespace App\Http\Controllers\Api\V1\Shared\Providers;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Shared\Providers\ProviderServiceDetailResource;
use App\Services\Providers\ProviderDirectory;
use App\Traits\ApiResponse;

class ShowProviderServiceController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ProviderDirectory $providers) {}

    /**
     * One service as this provider offers it, with her inclusions and add-ons. Public.
     */
    public function __invoke(int $provider, int $service)
    {
        $providerService = $this->providers->service($provider, $service);

        return $this->success(new ProviderServiceDetailResource(
            $providerService,
            $this->providers->inclusions($providerService),
            $this->providers->addons($providerService),
        ));
    }
}
