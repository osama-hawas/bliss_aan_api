<?php

namespace App\Http\Controllers\Api\V1\Provider\Setup;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Provider\Setup\SetCoverageRequest;
use App\Http\Resources\Api\V1\Provider\Setup\ProfileResource;
use App\Services\Providers\ProviderSetup;
use App\Traits\ApiResponse;

class SetCoverageController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ProviderSetup $setup) {}

    public function __invoke(SetCoverageRequest $request)
    {
        $ids = array_map('intval', $request->validated('coverage_city_ids'));
        $provider = $this->setup->setCoverage($this->setup->activeProvider($request->user()), $ids);

        return $this->success(new ProfileResource(ProfileResource::load($provider)), __('Coverage saved.'));
    }
}
