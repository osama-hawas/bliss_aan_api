<?php

namespace App\Http\Controllers\Api\V1\Provider\Setup;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Provider\Setup\ProviderServiceResource;
use App\Services\Providers\ProviderSetup;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

class ListServicesController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ProviderSetup $setup) {}

    public function __invoke(Request $request)
    {
        $services = $this->setup->activeProvider($request->user())->services()
            ->with(['service.category', 'service.inclusions', 'inclusionSettings', 'addons.addon'])
            ->orderBy('id')->get();

        return $this->success(ProviderServiceResource::collection($services));
    }
}
