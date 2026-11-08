<?php

namespace App\Http\Controllers\Api\V1\Provider\Setup;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Provider\Setup\SetInclusionsRequest;
use App\Http\Resources\Api\V1\Provider\Setup\ProviderServiceResource;
use App\Services\Providers\ProviderSetup;
use App\Traits\ApiResponse;

class SetInclusionsController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ProviderSetup $setup) {}

    public function __invoke(SetInclusionsRequest $request, int $service)
    {
        $offered = $this->setup->activeProvider($request->user())->services()->findOrFail($service);
        $offered = $this->setup->setInclusions($offered, array_map('intval', $request->validated('disabled_inclusion_ids')));

        return $this->success(new ProviderServiceResource(ProviderServiceResource::load($offered)), __('Service saved.'));
    }
}
