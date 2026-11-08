<?php

namespace App\Http\Controllers\Api\V1\Provider\Setup;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Provider\Setup\AddPortfolioRequest;
use App\Http\Resources\Api\V1\Provider\Setup\ProfileResource;
use App\Services\Providers\ProviderSetup;
use App\Traits\ApiResponse;

class AddPortfolioController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ProviderSetup $setup) {}

    public function __invoke(AddPortfolioRequest $request)
    {
        $provider = $this->setup->activeProvider($request->user());
        $this->setup->addPortfolio($provider, $request->user(), (int) $request->validated('media_id'), $request->validated('caption'));

        return $this->success(new ProfileResource(ProfileResource::load($provider)), __('Photo added.'), 201);
    }
}
