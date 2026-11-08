<?php

namespace App\Http\Controllers\Api\V1\Provider\Setup;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Provider\Setup\UpdateProfileRequest;
use App\Http\Resources\Api\V1\Provider\Setup\ProfileResource;
use App\Services\Providers\ProviderSetup;
use App\Traits\ApiResponse;

class UpdateProfileController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ProviderSetup $setup) {}

    public function __invoke(UpdateProfileRequest $request)
    {
        $provider = $this->setup->updateProfile($this->setup->activeProvider($request->user()), $request->validated());

        return $this->success(new ProfileResource(ProfileResource::load($provider)), __('Profile saved.'));
    }
}
