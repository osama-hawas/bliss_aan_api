<?php

namespace App\Http\Controllers\Api\V1\Provider\Setup;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Provider\Setup\UpdateServiceRequest;
use App\Http\Resources\Api\V1\Provider\Setup\ProviderServiceResource;
use App\Services\Providers\ProviderSetup;
use App\Traits\ApiResponse;

class UpdateServiceController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ProviderSetup $setup) {}

    public function __invoke(UpdateServiceRequest $request, int $service)
    {
        // Only her own service resolves; anything else is a 404.
        $offered = $this->setup->activeProvider($request->user())->services()->findOrFail($service);
        $offered = $this->setup->updateService($offered, $request->validated());

        return $this->success(new ProviderServiceResource(ProviderServiceResource::load($offered)), __('Service saved.'));
    }
}
