<?php

namespace App\Http\Controllers\Api\V1\Provider\Setup;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Provider\Setup\AddServiceRequest;
use App\Http\Resources\Api\V1\Provider\Setup\ProviderServiceResource;
use App\Services\Providers\ProviderSetup;
use App\Traits\ApiResponse;

class AddServiceController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ProviderSetup $setup) {}

    public function __invoke(AddServiceRequest $request)
    {
        $offered = $this->setup->addService($this->setup->activeProvider($request->user()), (int) $request->validated('service_id'), $request->places());

        return $this->success(new ProviderServiceResource(ProviderServiceResource::load($offered)), __('Service added.'), 201);
    }
}
