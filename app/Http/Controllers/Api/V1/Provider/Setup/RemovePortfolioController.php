<?php

namespace App\Http\Controllers\Api\V1\Provider\Setup;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Provider\Setup\ProfileResource;
use App\Services\Providers\ProviderSetup;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

class RemovePortfolioController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ProviderSetup $setup) {}

    public function __invoke(Request $request, int $photo)
    {
        $provider = $this->setup->activeProvider($request->user());
        $provider->portfolioItems()->findOrFail($photo)->delete();

        return $this->success(new ProfileResource(ProfileResource::load($provider)), __('Photo removed.'));
    }
}
